<?php

use App\Classes\Settings;
use App\Console\Commands\CronJob;
use App\Console\Commands\FetchEmails;
use App\Console\Commands\ReconcileInvoicePaymentInitiations;
use App\Console\Commands\RecoverBillingChargeAttempts;
use App\Console\Commands\RecoverServiceJobDispatches;
use App\Console\Commands\RecoverServiceUpgradeDispatches;
use App\Console\Commands\ScheduleHeartbeatCommand;
use App\Console\Commands\TelemetryCommand;
use Illuminate\Support\Facades\Schedule;

Schedule::command(ScheduleHeartbeatCommand::class)->description('Updates the last scheduler run time')->everyMinute()->onOneServer();
Schedule::command(RecoverBillingChargeAttempts::class)
    ->description('Recovers durable saved-payment-method renewal charges')
    ->everyMinute()
    ->withoutOverlapping(5)
    ->onOneServer();
Schedule::command(ReconcileInvoicePaymentInitiations::class)
    ->description('Reconciles durable interactive provider payments')
    ->everyMinute()
    ->withoutOverlapping(5)
    ->onOneServer();
Schedule::command(RecoverServiceUpgradeDispatches::class)
    ->description('Recovers durable service upgrade queue dispatches')
    ->everyMinute()
    ->withoutOverlapping(5)
    ->onOneServer();
Schedule::command(RecoverServiceJobDispatches::class)
    ->description('Recovers durable server lifecycle queue dispatches')
    ->everyMinute()
    ->withoutOverlapping(5)
    ->onOneServer();
Schedule::command(CronJob::class)->description('Runs daily to send out invoices, suspend servers, etc.')->dailyAt(config('settings.cronjob_time', '00:00'))->onOneServer();
Schedule::command(FetchEmails::class)->description('Import ticket emails using IMAP')->everyFiveMinutes()->withoutOverlapping()->onOneServer();

if (config('app.telemetry_enabled')) {
    $settings = Settings::getTelemetry();
    Schedule::command(TelemetryCommand::class)->description('Sends telemetry data')->dailyAt($settings['hour'] . ':' . $settings['minute']);
}
