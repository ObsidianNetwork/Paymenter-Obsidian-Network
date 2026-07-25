<?php

namespace App\Console\Commands;

use App\Helpers\ExtensionHelper;
use App\Helpers\NotificationHelper;
use App\Jobs\Server\SuspendJob;
use App\Jobs\Server\TerminateJob;
use App\Models\CronStat;
use App\Models\DebugLog;
use App\Models\Invoice;
use App\Models\Notification;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Services\Invoice\CancelInvoiceService;
use App\Services\ServiceUpgrade\ServiceUpgradeService;
use App\Models\Setting;
use App\Models\Ticket;
use App\Services\Service\RenewServiceService;
use App\Services\Service\DurableFulfillmentService;
use App\Services\Service\FulfillmentStatusTransitionService;
use App\Services\Service\ProductStockService;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

class CronJob extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:cron-job';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run automated tasks';

    private int $successFullCharges = 0;

    /**
     * Execute the console command.
     */
    public function handle()
    {
        Config::set('audit.console', true);

        DB::beginTransaction();

        try {
            // Send invoices if due date is x days away
            $this->runCronJob('invoices_created', function ($number = 0) {
                Service::where('status', 'active')->where('expires_at', '<', now()->addDays((int) config('settings.cronjob_invoice', 7)))->get()->each(function ($service) use (&$number) {
                    // Does the service have already a pending invoice?
                    if ($service->invoices()->where('status', 'pending')->exists() || $service->cancellation()->exists()) {
                        return;
                    }

                    // Calculate if we should edit the price because of the coupon
                    if ($service->coupon) {
                        // Calculate what iteration of the coupon we are in
                        $iteration = $service->invoices()->count() + 1;
                        if ($iteration == $service->coupon->recurring) {
                            // Calculate the price
                            $service->price = $service->calculatePrice();
                            $service->save();
                        }
                    }

                    // If service price is 0, immediately activate next period
                    if ($service->price <= 0) {
                        (new RenewServiceService)->handle($service);
                        $number++;

                        return;
                    }

                    // Create invoice
                    $invoice = $service->invoices()->make([
                        'user_id' => $service->user_id,
                        'status' => 'pending',
                        'due_at' => $service->expires_at,
                        'currency_code' => $service->currency_code,
                    ]);

                    $invoice->save();
                    // Create invoice items
                    $invoice->items()->create([
                        'reference_id' => $service->id,
                        'reference_type' => Service::class,
                        'price' => $service->price,
                        'quantity' => $service->quantity,
                        'description' => $service->description,
                    ]);

                    $invoice = $invoice->refresh();

                    $this->payInvoiceWithCredits($invoice);

                    // Charge billing agreements
                    if ($service->billing_agreement_id && $invoice->fresh()->status === 'pending') {
                        DB::afterCommit(function () use ($invoice, $service) {
                            try {
                                ExtensionHelper::charge(
                                    $service->billingAgreement->gateway,
                                    $invoice,
                                    $service->billingAgreement
                                );

                                $this->successFullCharges++;
                            } catch (Exception $e) {
                                // Ignore errors here
                                NotificationHelper::invoicePaymentFailedNotification($invoice->user, $invoice);
                            }
                        });
                    }

                    $number++;
                });

                return $number;
            });

            $this->runCronJob('orders_cancelled', function ($number = 0) {
                // Cancel services if first invoice is not paid after x days
                Service::where('status', 'pending')->whereDoesntHave('invoices', function ($query) {
                    $query->where('status', 'paid');
                })->where('created_at', '<', now()->subDays((int) config('settings.cronjob_order_cancel', 7)))->get()->each(function ($service) use (&$number) {
                    // Capacity-backed checkout orders are governed by their
                    // immutable guarantee timestamp, not this configurable
                    // generic order-age policy.
                    if (app(DurableFulfillmentService::class)->isReservationBacked($service)) {
                        return;
                    }

                    $this->cancelPendingInvoices(
                        $service,
                        'The unpaid order expired.'
                    );

                    $dynamicCancellation = $this->requestDynamicCancellation($service);
                    if (! $dynamicCancellation) {
                        $service->update(['status' => 'cancelled']);
                    }
                    if (! $dynamicCancellation && $service->product->stock !== null) {
                        app(ProductStockService::class)->release($service);
                    }

                    $number++;
                });

                return $number;
            });

            $this->runCronJob('upgrade_invoices_updated', function ($number = 0) {
                // Upgrade quotes are immutable. Expire unpaid commitments at
                // their invoice boundary; never silently reprice them. The
                // Dynamic Pterodactyl scheduler is the sole authority for
                // capacity-backed upgrades because it must release stock and
                // preserve partial/in-flight payment evidence atomically.
                ServiceUpgrade::query()
                    ->whereIn('status', [
                        ServiceUpgrade::STATUS_PENDING,
                        ServiceUpgrade::STATUS_AWAITING_PAYMENT,
                    ])
                    ->whereHas('invoice', fn ($query) => $query
                        ->where('status', \App\Models\Invoice::STATUS_PENDING)
                        ->where('due_at', '<=', now()))
                    ->get()
                    ->each(function ($upgrade) use (&$number): void {
                        $payments = app(
                            \App\Services\Invoice\CapacityInvoicePaymentService::class
                        );
                        if ($payments->isCapacityBacked($upgrade->invoice)) {
                            return;
                        }
                        if ($payments->hasInFlightOrSucceededPayment($upgrade->invoice)) {
                            $payments->requireAttention(
                                $upgrade->invoice,
                                'The upgrade invoice expired after a partial or in-flight payment. Refund or account-credit review is required.'
                            );
                            $upgrade->forceFill([
                                'status' => ServiceUpgrade::STATUS_NEEDS_ATTENTION,
                                'last_error' => 'The upgrade invoice expired with payment activity.',
                                'failed_at' => now(),
                            ])->save();
                            $number++;

                            return;
                        }
                        app(ServiceUpgradeService::class)->cancel(
                            $upgrade,
                            'The unpaid upgrade invoice expired.'
                        );
                        $number++;
                    });

                return $number;
            });

            $this->runCronJob('services_suspended', function ($number = 0) {
                // Suspend orders if due date is overdue for x days
                Service::where('status', 'active')->where('expires_at', '<', now()->subDays((int) config('settings.cronjob_order_suspend', 2)))->get()->each(function ($service) use (&$number) {
                    SuspendJob::dispatch($service);

                    FulfillmentStatusTransitionService::run(
                        $service,
                        fn () => $service->update(['status' => Service::STATUS_SUSPENDED])
                    );
                    $number++;
                });

                return $number;
            });

            $this->runCronJob('services_terminated', function ($number = 0) {
                // Terminate orders if due date is overdue for x days
                Service::where('status', 'suspended')->where('expires_at', '<', now()->subDays((int) config('settings.cronjob_order_terminate', 14)))->each(function ($service) use (&$number) {
                    // Invoice is the first lifecycle lock everywhere; payment
                    // also locks invoice before service.
                    $this->cancelPendingInvoices(
                        $service,
                        'The overdue service was terminated.'
                    );
                    $dynamicCancellation = $this->requestDynamicCancellation($service);
                    if ($dynamicCancellation) {
                        $service->refresh();
                    } else {
                        DB::afterCommit(fn () => TerminateJob::dispatch($service));
                        $service->update(['status' => 'cancelled']);
                    }
                    $number++;
                });

                return $number;
            });

            $this->runCronJob('tickets_closed', function ($number = 0) {
                // Close tickets if no response for x days
                Ticket::where('status', 'replied')->each(function ($ticket) use (&$number) {
                    $lastMessage = $ticket->messages()->latest('created_at')->first();
                    if ($lastMessage && $lastMessage->created_at < now()->subDays((int) config('settings.cronjob_close_ticket', 7))) {
                        $ticket->update(['status' => 'closed']);
                        $number++;
                    }
                });

                return $number;
            });

            $this->runCronJob('email_logs_deleted', function ($number = 0) {
                $number = Notification::where('created_at', '<', now()->subDays((int) config('settings.cronjob_delete_email_logs', 90)))->count();
                // Delete email logs older then x
                Notification::where('created_at', '<', now()->subDays((int) config('settings.cronjob_delete_email_logs', 90)))->delete();

                return $number;
            });

        } catch (Exception $e) {
            DB::rollBack();

            NotificationHelper::sendSystemEmailNotification('Cron Job Error', <<<HTML
                An error occurred while running the cron job:<br>
                <pre>{$e->getMessage()}.</pre><br>
                Please check the system and application logs for more details.
                HTML);

            throw $e;
        }

        DB::commit();

        Setting::updateOrCreate(
            ['key' => 'last_cron_run', 'settingable_type' => CronStat::class],
            ['value' => now()->toDateTimeString(), 'type' => 'string']
        );

        CronStat::create([
            'key' => 'invoice_charged',
            'value' => $this->successFullCharges,
            'date' => now()->toDateString(),
        ]);

        $this->info('Successfully charged ' . $this->successFullCharges . ' invoices.');

        // Remove old debug logs
        DebugLog::where('created_at', '<', now()->subDays(30))->delete();

        // Check for updates
        $this->info('Checking for updates...');

        $this->call(CheckForUpdates::class);
    }

    private function cancelPendingInvoices(Service $service, string $reason): void
    {
        $service->invoices()
            ->where('status', Invoice::STATUS_PENDING)
            ->orderBy('invoices.id')
            ->get()
            ->each(
                fn (Invoice $invoice) => app(CancelInvoiceService::class)
                    ->handle($invoice, $reason)
            );
    }

    private function requestDynamicCancellation(Service $service): bool
    {
        return app(DurableFulfillmentService::class)
            ->requestCancellation($service);
    }

    private function payInvoiceWithCredits(Invoice $invoice): void
    {
        if (!config('settings.credits_auto_use', true)) {
            return;
        }
        $user = $invoice->user;
        $credits = $user->credits()->where('currency_code', $invoice->currency_code)->first();
        if ($invoice->remaining > 0 && $credits && $credits->amount >= $invoice->remaining) {
            $credits->amount -= $invoice->remaining;
            $credits->save();

            ExtensionHelper::addPayment($invoice->id, null, amount: $invoice->remaining, isCreditTransaction: true);
        }
    }

    /**
     * Function to run a specific cron job by its key.
     */
    private function runCronJob(string $key, callable $callback): void
    {
        $items = $callback() ?? 0;

        CronStat::create([
            'key' => $key,
            'value' => $items,
            'date' => now()->toDateString(),
        ]);

        $this->info("Cronjob task '" . __('admin.cronjob.' . $key) . "' completed: Processed " . $items . ' items.');
    }
}
