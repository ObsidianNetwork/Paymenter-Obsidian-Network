<?php

namespace App\Console\Commands\Extension;

use App\Services\Extensions\ExtensionLifecycleGuard;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class Install extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:extension:install {type} {name}';

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
            ->assertCanActivate((string) $this->argument('name'));

        $extensionClass = 'Paymenter\\Extensions\\' . ucfirst($this->argument('type')) . 's\\' . ucfirst($this->argument('name')) . '\\' . ucfirst($this->argument('name'));
        if (!class_exists($extensionClass)) {
            $this->error("The extension class {$extensionClass} does not exist.");

            return Command::FAILURE;
        }

        $extensionInstance = new $extensionClass;
        if (method_exists($extensionInstance, 'installed')) {
            try {
                $extensionInstance->installed();
            } catch (\Exception $e) {
                Log::error("Error during installation of extension {$this->argument('name')}: " . $e->getMessage());

                $this->error('An error occurred while installing the extension: ' . $e->getMessage());

                return Command::FAILURE;
            }
        }

        return Command::SUCCESS;
    }
}
