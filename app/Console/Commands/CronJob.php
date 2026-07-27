<?php

namespace App\Console\Commands;

use App\Enums\InvoiceTransactionStatus;
use App\Helpers\NotificationHelper;
use App\Models\BillingAgreement;
use App\Models\CronStat;
use App\Models\DebugLog;
use App\Models\Invoice;
use App\Models\Notification;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Models\Setting;
use App\Models\Ticket;
use App\Services\Invoice\BillingChargeAttemptService;
use App\Services\Invoice\CancelInvoiceService;
use App\Services\Invoice\CapacityInvoicePaymentService;
use App\Services\Invoice\CreditInvoicePaymentService;
use App\Services\Service\DurableFulfillmentService;
use App\Services\Service\FulfillmentStatusTransitionService;
use App\Services\Service\ProductStockService;
use App\Services\Service\RenewServiceService;
use App\Services\Service\ServiceJobDispatchService;
use App\Services\ServiceUpgrade\ServiceUpgradeMutationCoordinator;
use App\Services\ServiceUpgrade\ServiceUpgradeService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Throwable;

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

    /** @var list<array{task: string, row: string, error: Throwable}> */
    private array $failures = [];

    /**
     * Execute the console command.
     */
    public function handle()
    {
        Config::set('audit.console', true);

        // Send invoices if due date is x days away
        $this->runCronJob('invoices_created', function ($number = 0) {
            Service::where('status', Service::STATUS_ACTIVE)
                ->where(
                    'expires_at',
                    '<',
                    now()->addDays(
                        (int) config('settings.cronjob_invoice', 7)
                    )
                )
                ->get(['id'])
                ->each(function (Service $candidate) use (&$number): void {
                    if (
                        $this->runCronRow(
                            'invoices_created',
                            "service:{$candidate->id}",
                            fn (): bool => $this
                                ->createRenewalForService(
                                    (int) $candidate->id
                                )
                        )
                    ) {
                        $number++;
                    }
                });

            return $number;
        });

        $this->runCronJob('orders_cancelled', function ($number = 0) {
            // Cancel services if first invoice is not paid after x days
            Service::where('status', 'pending')->whereDoesntHave('invoices', function ($query) {
                $query->where('status', 'paid');
            })->where('created_at', '<', now()->subDays((int) config('settings.cronjob_order_cancel', 7)))->get(['id'])->each(function (Service $candidate) use (&$number): void {
                if (
                    $this->runCronRow(
                        'orders_cancelled',
                        "service:{$candidate->id}",
                        function () use ($candidate): bool {
                            // Invoice is the first lifecycle lock. This makes
                            // a gateway settlement either complete before this
                            // row is evaluated or wait until cancellation has
                            // committed.
                            $invoices = $this->lockServiceInvoices(
                                (int) $candidate->id
                            );
                            if (
                                $invoices->contains(
                                    fn (Invoice $invoice): bool => $this->invoiceReferencesService(
                                        $invoice,
                                        (int) $candidate->id
                                    )
                                        && $invoice->status
                                            === Invoice::STATUS_PAID
                                )
                            ) {
                                return false;
                            }

                            $service = Service::query()
                                ->whereKey($candidate->id)
                                ->lockForUpdate()
                                ->first();
                            if (
                                $service === null
                                || $service->status !== Service::STATUS_PENDING
                                || $service->created_at->greaterThanOrEqualTo(
                                    now()->subDays(
                                        (int) config(
                                            'settings.cronjob_order_cancel',
                                            7
                                        )
                                    )
                                )
                            ) {
                                return false;
                            }

                            // Capacity-backed checkout orders are governed by
                            // their immutable guarantee timestamp, not this
                            // configurable generic order-age policy.
                            if (
                                app(DurableFulfillmentService::class)
                                    ->isReservationBacked($service)
                            ) {
                                return false;
                            }

                            $activeUpgrades = ServiceUpgrade::query()
                                ->where('service_id', $service->id)
                                ->whereIn(
                                    'status',
                                    ServiceUpgrade::activeStatuses()
                                )
                                ->orderBy('id')
                                ->lockForUpdate()
                                ->get();
                            $this->cancelLockedPendingInvoices(
                                $invoices,
                                $activeUpgrades,
                                (int) $service->id,
                                'The unpaid order expired.'
                            );
                            $service->refresh();
                            if (
                                $service->status !== Service::STATUS_PENDING
                                || $service->created_at->greaterThanOrEqualTo(
                                    now()->subDays(
                                        (int) config(
                                            'settings.cronjob_order_cancel',
                                            7
                                        )
                                    )
                                )
                            ) {
                                return false;
                            }

                            $dynamicCancellation =
                                $this->requestDynamicCancellation($service);
                            if (!$dynamicCancellation) {
                                FulfillmentStatusTransitionService::run(
                                    $service,
                                    fn () => $service->update([
                                        'status' => Service::STATUS_CANCELLED,
                                    ])
                                );
                            }
                            if (
                                !$dynamicCancellation
                                && $service->product->stock !== null
                            ) {
                                app(ProductStockService::class)
                                    ->release($service);
                            }

                            return true;
                        }
                    )
                ) {
                    $number++;
                }
            });

            return $number;
        });

        $this->runCronJob('upgrade_invoices_updated', function ($number = 0) {
            // Upgrade quotes are immutable. Expire unpaid commitments at their
            // invoice boundary; never silently reprice them. The Dynamic
            // Pterodactyl scheduler is the sole authority for capacity-backed
            // upgrades because it must release stock and preserve partial or
            // in-flight payment evidence atomically.
            ServiceUpgrade::query()
                ->whereIn('status', [
                    ServiceUpgrade::STATUS_PENDING,
                    ServiceUpgrade::STATUS_AWAITING_PAYMENT,
                ])
                ->whereHas('invoice', fn ($query) => $query
                    ->where('status', Invoice::STATUS_PENDING)
                    ->where('due_at', '<=', now()))
                ->get(['id'])
                ->each(
                    function (
                        ServiceUpgrade $candidate
                    ) use (&$number): void {
                        if (
                            $this->runCronRow(
                                'upgrade_invoices_updated',
                                "upgrade:{$candidate->id}",
                                fn (): bool => $this
                                    ->expireUpgradeInvoice(
                                        (int) $candidate->id
                                    )
                            )
                        ) {
                            $number++;
                        }
                    }
                );

            return $number;
        });

        $this->runCronJob('services_suspended', function ($number = 0) {
            // Suspend orders if due date is overdue for x days.
            Service::where('status', Service::STATUS_ACTIVE)
                ->where(
                    'expires_at',
                    '<',
                    now()->subDays(
                        (int) config('settings.cronjob_order_suspend', 2)
                    )
                )
                ->get(['id'])
                ->each(function (Service $candidate) use (&$number): void {
                    if (
                        $this->runCronRow(
                            'services_suspended',
                            "service:{$candidate->id}",
                            function () use ($candidate): bool {
                                $service = Service::query()
                                    ->whereKey($candidate->id)
                                    ->lockForUpdate()
                                    ->first();
                                if (
                                    $service === null
                                    || $service->status
                                        !== Service::STATUS_ACTIVE
                                    || $service->expires_at === null
                                    || $service->expires_at
                                        ->greaterThanOrEqualTo(
                                            now()->subDays(
                                                (int) config(
                                                    'settings.cronjob_order_suspend',
                                                    2
                                                )
                                            )
                                        )
                                ) {
                                    return false;
                                }

                                FulfillmentStatusTransitionService::run(
                                    $service,
                                    fn () => $service->update([
                                        'status' => Service::STATUS_SUSPENDED,
                                    ])
                                );
                                app(ServiceJobDispatchService::class)
                                    ->requestSuspend($service);

                                return true;
                            }
                        )
                    ) {
                        $number++;
                    }
                });

            return $number;
        });

        $this->runCronJob('services_terminated', function ($number = 0) {
            // Terminate orders if due date is overdue for x days.
            Service::where('status', Service::STATUS_SUSPENDED)
                ->where(
                    'expires_at',
                    '<',
                    now()->subDays(
                        (int) config('settings.cronjob_order_terminate', 14)
                    )
                )
                ->get(['id'])
                ->each(function (Service $candidate) use (&$number): void {
                    if (
                        $this->runCronRow(
                            'services_terminated',
                            "service:{$candidate->id}",
                            function () use ($candidate): bool {
                                $invoices = $this->lockServiceInvoices(
                                    (int) $candidate->id
                                );
                                $service = Service::query()
                                    ->whereKey($candidate->id)
                                    ->lockForUpdate()
                                    ->first();
                                if (
                                    $service === null
                                    || $service->status
                                        !== Service::STATUS_SUSPENDED
                                    || $service->expires_at === null
                                    || $service->expires_at
                                        ->greaterThanOrEqualTo(
                                            now()->subDays(
                                                (int) config(
                                                    'settings.cronjob_order_terminate',
                                                    14
                                                )
                                            )
                                        )
                                ) {
                                    return false;
                                }

                                $activeUpgrades = ServiceUpgrade::query()
                                    ->where('service_id', $service->id)
                                    ->whereIn(
                                        'status',
                                        ServiceUpgrade::activeStatuses()
                                    )
                                    ->orderBy('id')
                                    ->lockForUpdate()
                                    ->get();

                                // Invoice is the first lifecycle lock
                                // everywhere; payment also locks invoice before
                                // service.
                                $this->cancelLockedPendingInvoices(
                                    $invoices,
                                    $activeUpgrades,
                                    (int) $service->id,
                                    'The overdue service was terminated.'
                                );
                                $service->refresh();
                                if (
                                    $service->status
                                        !== Service::STATUS_SUSPENDED
                                    || $service->expires_at === null
                                    || $service->expires_at
                                        ->greaterThanOrEqualTo(
                                            now()->subDays(
                                                (int) config(
                                                    'settings.cronjob_order_terminate',
                                                    14
                                                )
                                            )
                                        )
                                ) {
                                    return false;
                                }
                                $dynamicCancellation =
                                    $this->requestDynamicCancellation(
                                        $service
                                    );
                                if ($dynamicCancellation) {
                                    $service->refresh();
                                } else {
                                    FulfillmentStatusTransitionService::run(
                                        $service,
                                        fn () => $service->update([
                                            'status' => Service::STATUS_CANCELLED,
                                        ])
                                    );
                                    app(ServiceJobDispatchService::class)
                                        ->requestTerminate($service);
                                }

                                return true;
                            }
                        )
                    ) {
                        $number++;
                    }
                });

            return $number;
        });

        $this->runCronJob('tickets_closed', function ($number = 0) {
            // Close tickets if no response for x days.
            Ticket::where('status', 'replied')
                ->get(['id'])
                ->each(function (Ticket $candidate) use (&$number): void {
                    if (
                        $this->runCronRow(
                            'tickets_closed',
                            "ticket:{$candidate->id}",
                            function () use ($candidate): bool {
                                $ticket = Ticket::query()
                                    ->whereKey($candidate->id)
                                    ->lockForUpdate()
                                    ->first();
                                if (
                                    $ticket === null
                                    || $ticket->status !== 'replied'
                                ) {
                                    return false;
                                }
                                $lastMessage = $ticket->messages()
                                    ->latest('created_at')
                                    ->first();
                                if (
                                    $lastMessage === null
                                    || $lastMessage->created_at
                                        ->greaterThanOrEqualTo(
                                            now()->subDays(
                                                (int) config(
                                                    'settings.cronjob_close_ticket',
                                                    7
                                                )
                                            )
                                        )
                                ) {
                                    return false;
                                }

                                $ticket->update(['status' => 'closed']);

                                return true;
                            }
                        )
                    ) {
                        $number++;
                    }
                });

            return $number;
        });

        $this->runCronJob('email_logs_deleted', function ($number = 0) {
            $number = DB::transaction(function (): int {
                $query = Notification::where(
                    'created_at',
                    '<',
                    now()->subDays(
                        (int) config(
                            'settings.cronjob_delete_email_logs',
                            90
                        )
                    )
                );
                $count = $query->count();
                $query->delete();

                return $count;
            }, 5);

            return $number;
        });

        Setting::updateOrCreate(
            ['key' => 'last_cron_run', 'settingable_type' => CronStat::class],
            ['value' => now()->toDateTimeString(), 'type' => 'string']
        );

        $this->successFullCharges = app(
            BillingChargeAttemptService::class
        )->claimUnreportedSettlements();
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

        $this->reportFailures();

        return self::SUCCESS;
    }

    /**
     * Expire one generic upgrade while holding the same global lock order as
     * gateway settlement: invoice -> service -> upgrade -> payment evidence.
     * A gateway that owns the invoice lock first wins and is revalidated here;
     * otherwise the gateway waits until cancellation or manual-attention state
     * has committed.
     */
    private function expireUpgradeInvoice(int $upgradeId): bool
    {
        if (DB::transactionLevel() === 0) {
            throw new \RuntimeException(
                'Upgrade expiry requires a database transaction.'
            );
        }

        $identity = ServiceUpgrade::query()
            ->whereKey($upgradeId)
            ->first(['invoice_id', 'service_id']);
        if (
            $identity === null
            || $identity->invoice_id === null
            || $identity->service_id === null
        ) {
            return false;
        }

        $invoice = Invoice::query()
            ->whereKey($identity->invoice_id)
            ->lockForUpdate()
            ->first();
        if ($invoice === null) {
            return false;
        }

        $service = Service::query()
            ->whereKey($identity->service_id)
            ->lockForUpdate()
            ->first();
        if ($service === null) {
            return false;
        }

        $upgrade = ServiceUpgrade::query()
            ->whereKey($upgradeId)
            ->lockForUpdate()
            ->first();
        if (
            $upgrade === null
            || (int) $upgrade->invoice_id !== (int) $invoice->id
            || (int) $upgrade->service_id !== (int) $service->id
            || !in_array(
                $upgrade->status,
                [
                    ServiceUpgrade::STATUS_PENDING,
                    ServiceUpgrade::STATUS_AWAITING_PAYMENT,
                ],
                true
            )
            || $invoice->status !== Invoice::STATUS_PENDING
            || $invoice->due_at === null
            || $invoice->due_at->isFuture()
        ) {
            return false;
        }

        $payments = app(CapacityInvoicePaymentService::class);
        if ($payments->isCapacityBacked($invoice)) {
            return false;
        }

        // Lock every existing evidence row while the invoice lock prevents
        // coordinated gateway writers from inserting a new one. Inspect the
        // locked models rather than issuing an unlocked EXISTS query.
        $paymentEvidence = $invoice->transactions()
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'status']);
        $hasPaymentActivity = $paymentEvidence->contains(
            fn ($transaction): bool => in_array(
                $transaction->status,
                [
                    InvoiceTransactionStatus::Processing,
                    InvoiceTransactionStatus::Succeeded,
                ],
                true
            )
        );
        if ($hasPaymentActivity) {
            $payments->requireAttention(
                $invoice,
                'The upgrade invoice expired after a partial or in-flight payment. Refund or account-credit review is required.'
            );
            $upgrade->forceFill([
                'status' => ServiceUpgrade::STATUS_NEEDS_ATTENTION,
                'last_error' => 'The upgrade invoice expired with payment activity.',
                'failed_at' => now(),
            ]);
            ServiceUpgradeMutationCoordinator::save($upgrade);

            return true;
        }

        app(ServiceUpgradeService::class)->cancel(
            $upgrade,
            'The unpaid upgrade invoice expired.'
        );

        return true;
    }

    private function createRenewalForService(int $serviceId): bool
    {
        $service = Service::query()
            ->whereKey($serviceId)
            ->lockForUpdate()
            ->first();
        if (
            $service === null
            || $service->status !== Service::STATUS_ACTIVE
            || $service->expires_at === null
            || $service->expires_at->greaterThanOrEqualTo(
                now()->addDays(
                    (int) config('settings.cronjob_invoice', 7)
                )
            )
        ) {
            return false;
        }

        $activeUpgrades = ServiceUpgrade::query()
            ->where('service_id', $service->id)
            ->whereIn('status', ServiceUpgrade::activeStatuses())
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id']);
        if ($activeUpgrades->isNotEmpty()) {
            return false;
        }

        if (
            $service->invoices()
                ->where('status', Invoice::STATUS_PENDING)
                ->exists()
            || $service->cancellation()->exists()
        ) {
            return false;
        }

        // A finite recurring coupon applies to exactly this many service
        // periods, including zero-price periods that create no invoice.
        // Before issuing the first period beyond that allowance, replace the
        // stored discounted recurring obligation with the full current price.
        if (
            $service->coupon
            && $service->coupon->recurring !== null
            && (int) $service->coupon->recurring > 0
        ) {
            $completedCycles =
                (int) $service->billing_cycles_completed;
            if (
                $completedCycles <= 0
                || $completedCycles
                    === (int) $service->coupon->recurring
            ) {
                $service->price = $service->calculatePrice();
                FulfillmentStatusTransitionService::run(
                    $service,
                    fn () => $service->save()
                );
            }
        }

        // Free renewal must remain behind the same service and active-upgrade
        // locks as invoiced renewal.
        if ($service->price <= 0) {
            (new RenewServiceService)->handle($service);

            return true;
        }

        $invoice = $service->invoices()->make([
            'user_id' => $service->user_id,
            'status' => Invoice::STATUS_PENDING,
            'due_at' => $service->expires_at,
            'currency_code' => $service->currency_code,
        ]);
        $invoice->save();
        $invoice->items()->create([
            'reference_id' => $service->id,
            'reference_type' => Service::class,
            'price' => $service->price,
            'quantity' => $service->quantity,
            'description' => $service->description,
        ]);
        $invoice = $invoice->refresh();

        $this->payInvoiceWithCredits($invoice);

        if (
            $service->billing_agreement_id
            && $invoice->fresh()->status === Invoice::STATUS_PENDING
        ) {
            $billingAgreement = BillingAgreement::query()
                ->whereKey($service->billing_agreement_id)
                ->first();
            if ($billingAgreement !== null) {
                try {
                    app(BillingChargeAttemptService::class)
                        ->createForRenewal(
                            $invoice->fresh(),
                            $billingAgreement
                        );
                } catch (Throwable $error) {
                    // An invalid or unavailable saved method must not roll
                    // back the renewal invoice itself. Leave the invoice
                    // payable by another method, report the deterministic
                    // automatic-charge failure, and notify the customer only
                    // after this renewal row commits.
                    $this->recordFailure(
                        'invoices_created',
                        "service:{$service->id}:automatic-charge",
                        $error
                    );
                    $invoiceId = (int) $invoice->id;
                    DB::afterCommit(static function () use (
                        $invoiceId
                    ): void {
                        try {
                            $invoice = Invoice::query()
                                ->find($invoiceId);
                            if (
                                $invoice !== null
                                && $invoice->user !== null
                            ) {
                                NotificationHelper::invoicePaymentFailedNotification(
                                    $invoice->user,
                                    $invoice
                                );
                            }
                        } catch (Throwable $notificationError) {
                            report($notificationError);
                        }
                    });
                }
            }
        }

        return true;
    }

    /**
     * Lock every invoice that can drive this service before the service row.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Invoice>
     */
    private function lockServiceInvoices(
        int $serviceId
    ): \Illuminate\Database\Eloquent\Collection {
        if (DB::transactionLevel() === 0) {
            throw new \RuntimeException(
                'Cron lifecycle invoice locks require a database transaction.'
            );
        }

        return Invoice::query()
            ->whereHas(
                'items',
                fn ($query) => $query->where(
                    function ($referenceQuery) use ($serviceId): void {
                        $referenceQuery
                            ->where(function ($serviceQuery) use (
                                $serviceId
                            ): void {
                                $serviceQuery
                                    ->where(
                                        'reference_type',
                                        Service::class
                                    )
                                    ->where(
                                        'reference_id',
                                        $serviceId
                                    );
                            })
                            ->orWhere(function ($upgradeQuery) use (
                                $serviceId
                            ): void {
                                $upgradeQuery
                                    ->where(
                                        'reference_type',
                                        ServiceUpgrade::class
                                    )
                                    ->whereIn(
                                        'reference_id',
                                        ServiceUpgrade::query()
                                            ->select('id')
                                            ->where(
                                                'service_id',
                                                $serviceId
                                            )
                                    );
                            });
                    }
                )
            )
            ->with([
                'items' => fn ($query) => $query->select([
                    'id',
                    'invoice_id',
                    'reference_type',
                    'reference_id',
                ]),
            ])
            ->orderBy('invoices.id')
            ->lockForUpdate()
            ->get();
    }

    /**
     * @param  Collection<int, Invoice>  $invoices
     * @param  Collection<int, ServiceUpgrade>  $activeUpgrades
     */
    private function cancelLockedPendingInvoices(
        Collection $invoices,
        Collection $activeUpgrades,
        int $serviceId,
        string $reason
    ): void {
        $payments = app(CapacityInvoicePaymentService::class);
        foreach ($activeUpgrades as $upgrade) {
            if (!in_array($upgrade->status, [
                ServiceUpgrade::STATUS_PENDING,
                ServiceUpgrade::STATUS_AWAITING_PAYMENT,
            ], true)) {
                throw new \RuntimeException(
                    "Service upgrade {$upgrade->id} is {$upgrade->status}; termination requires payment or operator reconciliation first."
                );
            }

            $invoice = $invoices->firstWhere(
                'id',
                (int) $upgrade->invoice_id
            );
            if (
                $invoice === null
                || $invoice->status !== Invoice::STATUS_PENDING
            ) {
                throw new \RuntimeException(
                    "Active service upgrade {$upgrade->id} has no locked payable invoice."
                );
            }
            if ($payments->hasInFlightOrSucceededPayment($invoice)) {
                throw new \RuntimeException(
                    "Upgrade invoice {$invoice->id} has payment activity and requires reconciliation before service termination."
                );
            }
        }

        $directInvoices = $invoices->filter(
            fn (Invoice $invoice): bool => $invoice->status
                === Invoice::STATUS_PENDING
                && $this->invoiceReferencesService(
                    $invoice,
                    $serviceId
                )
        );
        foreach ($directInvoices as $invoice) {
            if ($payments->hasInFlightOrSucceededPayment($invoice)) {
                throw new \RuntimeException(
                    "Service invoice {$invoice->id} has payment activity and requires reconciliation before cancellation."
                );
            }
        }
        $directInvoices->each(
            fn (Invoice $invoice) => app(CancelInvoiceService::class)
                ->handle((int) $invoice->id, $reason)
        );

        foreach ($activeUpgrades as $upgrade) {
            app(ServiceUpgradeService::class)->cancel(
                $upgrade,
                $reason
            );
            $upgrade->refresh();
            $invoice = $invoices->firstWhere(
                'id',
                (int) $upgrade->invoice_id
            );
            $invoice?->refresh();
            if (
                $upgrade->status !== ServiceUpgrade::STATUS_CANCELLED
                || $invoice?->status !== Invoice::STATUS_CANCELLED
            ) {
                throw new \RuntimeException(
                    "Service upgrade {$upgrade->id} did not reach a cancelled terminal state."
                );
            }
        }
    }

    private function invoiceReferencesService(
        Invoice $invoice,
        int $serviceId
    ): bool {
        return $invoice->items->contains(
            fn ($item): bool => $item->reference_type === Service::class
                && (int) $item->reference_id === $serviceId
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

        app(CreditInvoicePaymentService::class)->pay(
            $invoice,
            allowPartial: false
        );
    }

    /**
     * Function to run a specific cron job by its key.
     */
    private function runCronJob(string $key, callable $callback): void
    {
        try {
            $items = $callback() ?? 0;
        } catch (Throwable $error) {
            $this->recordFailure($key, 'task', $error);
            $items = 0;
        }

        CronStat::create([
            'key' => $key,
            'value' => $items,
            'date' => now()->toDateString(),
        ]);

        $this->info("Cronjob task '" . __('admin.cronjob.' . $key) . "' completed: Processed " . $items . ' items.');
    }

    private function runCronRow(
        string $task,
        string $row,
        callable $callback
    ): bool {
        try {
            return DB::transaction($callback, 5) === true;
        } catch (Throwable $error) {
            $this->recordFailure($task, $row, $error);

            return false;
        }
    }

    private function recordFailure(
        string $task,
        string $row,
        Throwable $error
    ): void {
        report($error);
        $this->failures[] = [
            'task' => $task,
            'row' => $row,
            'error' => $error,
        ];
        $this->error(
            "Cronjob task '{$task}' failed for {$row}: "
            . $error->getMessage()
        );
    }

    private function reportFailures(): void
    {
        if ($this->failures === []) {
            return;
        }

        $details = collect($this->failures)
            ->take(20)
            ->map(function (array $failure): string {
                $task = htmlspecialchars(
                    $failure['task'],
                    ENT_QUOTES | ENT_SUBSTITUTE,
                    'UTF-8'
                );
                $row = htmlspecialchars(
                    $failure['row'],
                    ENT_QUOTES | ENT_SUBSTITUTE,
                    'UTF-8'
                );
                $message = htmlspecialchars(
                    $failure['error']->getMessage(),
                    ENT_QUOTES | ENT_SUBSTITUTE,
                    'UTF-8'
                );

                return "<li><strong>{$task}</strong> ({$row}): "
                    . "{$message}</li>";
            })
            ->implode('');
        $omitted = count($this->failures) - min(
            count($this->failures),
            20
        );
        if ($omitted > 0) {
            $details .= "<li>{$omitted} additional failures omitted.</li>";
        }

        try {
            NotificationHelper::sendSystemEmailNotification(
                'Cron Job Errors',
                count($this->failures)
                    . ' cron row(s) failed; later rows and tasks continued.'
                    . "<ul>{$details}</ul>"
                    . 'Please check the application logs for stack traces.'
            );
        } catch (Throwable $notificationError) {
            report($notificationError);
        }
    }
}
