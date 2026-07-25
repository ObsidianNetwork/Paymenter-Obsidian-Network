<?php

namespace App\Console\Commands\Extension;

use App\Services\Extensions\ExtensionLifecycleGuard;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

class Upgrade extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:extension:upgrade {type} {name} {oldVersion?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Internal command used to call upgrade on an extension';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        app(ExtensionLifecycleGuard::class)
            ->assertCanUpgrade((string) $this->argument('name'));

        $extensionClass = 'Paymenter\\Extensions\\' . ucfirst($this->argument('type')) . 's\\' . ucfirst($this->argument('name')) . '\\' . ucfirst($this->argument('name'));
        if (!class_exists($extensionClass)) {
            $this->error("The extension class {$extensionClass} does not exist.");

            return Command::FAILURE;
        }

        $extensionInstance = new $extensionClass;
        if (method_exists($extensionInstance, 'upgraded')) {
            try {
                $extensionInstance->upgraded($this->argument('oldVersion'));
            } catch (\Exception $e) {
                Log::error("Error during upgrade of extension {$this->argument('name')}: " . $e->getMessage());

                $this->error('An error occurred while upgrading the extension: ' . $e->getMessage());

                return Command::FAILURE;
            }
        }

        if (Artisan::call('queue:restart') !== 0) {
            $this->error(
                'Extension upgraded, but queue workers could not be signalled '
                .'to restart. Keep Paymenter in maintenance and restart them manually.'
            );

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
