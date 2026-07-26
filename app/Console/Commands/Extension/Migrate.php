<?php

namespace App\Console\Commands\Extension;

use App\Helpers\ExtensionHelper;
use App\Services\Extensions\ExtensionLifecycleGuard;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class Migrate extends Command
{
    protected $signature = 'app:extension:migrate
                            {type : Singular extension type, for example other}
                            {name : Extension class and directory name}
                            {--force : Run without the production confirmation prompt}';

    protected $description = 'Run one extension migration path and fail if any migration cannot be applied';

    public function handle(): int
    {
        $type = strtolower((string) $this->argument('type'));
        $name = (string) $this->argument('name');

        if (
            ! preg_match('/^[a-z][a-z0-9_-]*$/', $type)
            || ! preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/', $name)
        ) {
            $this->error('The extension type or name contains invalid characters.');

            return Command::FAILURE;
        }

        if (
            app()->environment('production')
            && ! $this->option('force')
            && ! $this->confirm("Run migrations for {$type}/{$name}?")
        ) {
            return Command::FAILURE;
        }

        $path = sprintf(
            'extensions/%ss/%s/database/migrations',
            ucfirst($type),
            $name
        );

        try {
            app(ExtensionLifecycleGuard::class)->assertCanUpgrade($name);

            $ran = ExtensionHelper::runMigrationsOrFail($path);
            $extension = ExtensionHelper::getExtension($type, $name);
            if (method_exists($extension, 'assertMigrationReady')) {
                $extension->assertMigrationReady();
            }
            app(ExtensionLifecycleGuard::class)
                ->assertCanActivate($name);
            if (Artisan::call('queue:restart') !== 0) {
                throw new \RuntimeException(
                    'Extension migrations/readiness completed, but queue '
                    .'workers could not be signalled to restart. Keep '
                    .'Paymenter in maintenance and restart them manually.'
                );
            }
        } catch (\Throwable $exception) {
            report($exception);
            $this->error($exception->getMessage());

            return Command::FAILURE;
        }

        if ($ran === []) {
            $this->info('No pending extension migrations.');
        } else {
            foreach ($ran as $migration) {
                $this->line("Migrated: {$migration}");
            }
        }

        return Command::SUCCESS;
    }
}
