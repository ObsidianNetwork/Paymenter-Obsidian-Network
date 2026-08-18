<?php

namespace App\Services\Extensions;

use App\Attributes\ExtensionMeta;
use App\Classes\Extension\Extension;
use App\Classes\Extension\Gateway;
use App\Classes\Extension\Server;
use App\Console\Commands\Extension\Install;
use App\Console\Commands\Extension\Upgrade;
use App\Helpers\ExtensionHelper;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use ReflectionClass;

class UploadExtensionService
{
    /**
     * Handle the uploaded extension file.
     * The added file is always a zip file.
     *
     * @return void
     */
    public function handle(string $filePath): string
    {
        // Validate the file type and size
        if (!file_exists($filePath) || !is_readable($filePath)) {
            throw new \Exception('File does not exist or is not readable.');
        }
        if (pathinfo($filePath, PATHINFO_EXTENSION) !== 'zip') {
            throw new \Exception('Invalid file type. Only zip files are allowed.');
        }

        // Extract the zip file
        $extractPath = storage_path('app/extensions/' . uniqid());

        if (!is_dir($extractPath) && !mkdir($extractPath, 0755, true)) {
            throw new \Exception('Failed to create extraction directory.');
        }

        $this->unzip($filePath, $extractPath);

        $destinationPath = null;
        $backupPath = null;
        $activated = false;
        $enteredMaintenance = false;
        try {
            // Define if the folder path is correct or we need to traverse it (based on .php files)
            $path = $this->validateExtensionPath($extractPath);

            // Find the php file extending either Extension, Server, or Gateway
            $type = $this->getExtensionType($path);

            // Move the files to the correct location
            $destinationPath = base_path('extensions/' . ucfirst($type['type']) . 's/' . $type['class']);
            $updating = false;
            $oldVersion = null;

            // Check if destination directory exists, if so, remove it
            if (is_dir($destinationPath)) {
                $updating = true;
            }
            $enteredMaintenance = $this->enterMaintenanceMode();

            if ($updating) {
                app(ExtensionLifecycleGuard::class)
                    ->assertCanUpgrade($type['class']);

                $oldVersion = $this->installedExtensionVersion(
                    $type['type'],
                    $type['class']
                );
                $backupPath = dirname($destinationPath) . '/.'
                    . basename($destinationPath) . '.backup-'
                    . bin2hex(random_bytes(8));
                if (!rename($destinationPath, $backupPath)) {
                    throw new \RuntimeException(
                        'Failed to preserve the installed extension before updating it.'
                    );
                }
            }

            if (!rename($path, $destinationPath)) {
                throw new \Exception('Failed to move the extension files to the destination.');
            }
            $activated = true;
        } catch (\Throwable $e) {
            // Clean up the extracted files in case of an error
            File::deleteDirectory($extractPath);
            $this->recoverFailedActivation(
                $destinationPath,
                $backupPath,
                $activated,
                false,
                $enteredMaintenance,
                $e
            );

            throw $e;
        }

        // Remove the extracted files
        File::deleteDirectory($extractPath);

        $schemaActivationStarted = false;
        try {
            // Extension lifecycle hooks may commit schema mutations before
            // returning or failing. From this point onward, recovery must keep
            // the application in maintenance until an operator completes a
            // compatible forward repair.
            $schemaActivationStarted = true;

            // Execute the upgraded method if it exists
            if ($updating) {
                $exitCode = Artisan::call(Upgrade::class, [
                    'type' => $type['type'],
                    'name' => $type['class'],
                    'oldVersion' => $oldVersion,
                ]);
            } else {
                $exitCode = Artisan::call(Install::class, [
                    'type' => $type['type'],
                    'name' => $type['class'],
                ]);
            }

            if ($exitCode !== 0) {
                $output = trim(Artisan::output());
                throw new \RuntimeException(
                    $output !== ''
                        ? "Extension lifecycle failed: {$output}"
                        : 'Extension lifecycle failed. Review the application log for details.'
                );
            }

            // The previous extension class may already be loaded in this PHP
            // process during an update. Run the newly installed destination's
            // migrations by path so schema activation never depends on that
            // stale class definition invoking its new upgraded() hook.
            $migrationPath = $destinationPath . '/database/migrations';
            if (is_dir($migrationPath)) {
                ExtensionHelper::runMigrationsOrFail(
                    'extensions/' . ucfirst($type['type']) . 's/'
                    . $type['class'] . '/database/migrations'
                );
            }
            $this->runDestinationReadinessHook($destinationPath);
            $this->restartQueueWorkers();
        } catch (\Throwable $exception) {
            $this->recoverFailedActivation(
                $destinationPath,
                $backupPath,
                true,
                $schemaActivationStarted,
                $enteredMaintenance,
                $exception
            );

            throw new \RuntimeException(
                'The extension lifecycle failed and the previous extension '
                . 'files were restored. Extension migrations are forward-only '
                . 'and were not rolled back. Paymenter remains in maintenance; '
                . 'install compatible extension files, complete a forward '
                . 'schema/readiness repair, restart queue workers, and run '
                . 'php artisan up only after verification. '
                . $exception->getMessage(),
                0,
                $exception
            );
        }

        if ($backupPath !== null && is_dir($backupPath)) {
            File::deleteDirectory($backupPath);
        }
        $this->leaveMaintenanceMode($enteredMaintenance);

        return $type['type'];
    }

    private function getExtensionType(string $path): array
    {
        $files = glob($path . '/*.php');
        $type = ['class' => null, 'type' => null];
        foreach ($files as $file) {
            // Read file
            $content = file_get_contents($file);
            if (preg_match('/namespace\s+(.+?);/', $content, $matches)) {
                if (!class_exists($matches[1] . '\\' . pathinfo(class_basename($file), PATHINFO_FILENAME))) {
                    // If the class is not loaded, include the file
                    require_once $file; // Include the file to load the class
                }
                $namespace = $matches[1];
                if (preg_match('/^\s*class\s+([A-Za-z_][A-Za-z0-9_]*)\s*(?:extends|implements|\{)/m', $content, $classMatches)) {
                    $className = $classMatches[1];
                    $fullClassName = $namespace . '\\' . $className;

                    // Only return className
                    $type['class'] = $className;
                    if (is_subclass_of($fullClassName, Server::class)) {
                        $type['type'] = 'server';
                    } elseif (is_subclass_of($fullClassName, Gateway::class)) {
                        $type['type'] = 'gateway';
                    } elseif (is_subclass_of($fullClassName, Extension::class)) {
                        $type['type'] = 'other';
                    }
                    if ($type['class'] && $type['type']) {
                        break; // Exit the loop if we found a valid class
                    }
                }
            }
        }
        if (!$type['class'] || !$type['type']) {
            throw new \Exception('No valid extension class found in the provided path.');
        }

        return $type;
    }

    private function validateExtensionPath(string $path, int $depth = 0): string
    {
        if ($depth > 1) {
            throw new \Exception('Maximum depth reached while validating extension path.');
        }
        // Check if the path contains a valid extension structure
        $files = glob($path . '/*.php');

        if (empty($files)) {
            // Retry it ONCE with the first subdirectory
            $subDirs = glob($path . '/*', GLOB_ONLYDIR);
            if (count($subDirs) > 0) {
                for ($i = 0; $i < count($subDirs); $i++) {
                    if (basename($subDirs[$i]) === '__MACOSX') {
                        continue;
                    }

                    if (glob($subDirs[$i] . '/*.php')) {
                        $newPath = $subDirs[$i];
                        break;
                    }
                }
                if (isset($newPath)) {
                    // Pass the new path with increased depth
                    return $this->validateExtensionPath($newPath, $depth + 1);
                }
            }

            throw new \Exception('No valid extension files found in the provided path.');
        }

        // Return the path if it contains valid PHP files
        return $path;
    }

    private function unzip(string $filePath, string $extractPath)
    {
        $zip = new \ZipArchive;
        if ($zip->open($filePath) === true) {
            $zip->extractTo($extractPath);
            $zip->close();

            // Remove the zip file after extraction
            File::delete($filePath);
        } else {
            throw new \Exception('Failed to open the zip file.');
        }
    }

    private function restorePreviousExtension(
        ?string $destinationPath,
        ?string $backupPath,
        bool $activated,
        \Throwable $original
    ): void {
        if ($destinationPath === null) {
            return;
        }

        if ($activated && is_dir($destinationPath)) {
            File::deleteDirectory($destinationPath);
        }
        if ($backupPath === null || !is_dir($backupPath)) {
            return;
        }
        if (!rename($backupPath, $destinationPath)) {
            throw new \RuntimeException(
                'The extension update failed and the previous extension files could not be restored: '
                . $original->getMessage(),
                0,
                $original
            );
        }
    }

    /**
     * Restore files after a failed activation. Once lifecycle execution starts,
     * schema changes may already have committed and the application must remain
     * down until an operator completes a compatible forward repair.
     */
    private function recoverFailedActivation(
        ?string $destinationPath,
        ?string $backupPath,
        bool $activated,
        bool $schemaActivationStarted,
        bool $enteredMaintenance,
        \Throwable $original
    ): void {
        $this->restorePreviousExtension(
            $destinationPath,
            $backupPath,
            $activated,
            $original
        );

        if (!$schemaActivationStarted) {
            $this->leaveMaintenanceMode($enteredMaintenance);
        }
    }

    private function installedExtensionVersion(
        string $type,
        string $class
    ): ?string {
        $extensionClass = 'Paymenter\\Extensions\\'
            . ucfirst($type) . 's\\'
            . ucfirst($class) . '\\'
            . ucfirst($class);
        if (!class_exists($extensionClass)) {
            return null;
        }

        $reflection = new ReflectionClass($extensionClass);
        $attributes = $reflection->getAttributes(ExtensionMeta::class);
        if ($attributes === []) {
            return null;
        }

        $version = $attributes[0]->newInstance()->version;

        return $version !== '' ? $version : null;
    }

    /**
     * Load the readiness contract directly from the newly activated tree.
     * The returned anonymous object/callable is not the extension's main class,
     * so an update remains correct when the previous class is already loaded.
     */
    private function runDestinationReadinessHook(string $destinationPath): void
    {
        $hookPath = $destinationPath . '/migration-readiness.php';
        if (!is_file($hookPath)) {
            return;
        }

        $hook = require $hookPath;
        if (is_callable($hook)) {
            $hook();

            return;
        }
        if (is_object($hook) && method_exists($hook, 'assertReady')) {
            $hook->assertReady();

            return;
        }

        throw new \RuntimeException(
            'The destination extension migration-readiness.php file must '
            . 'return a callable or an object with assertReady().'
        );
    }

    private function enterMaintenanceMode(): bool
    {
        if (app()->isDownForMaintenance()) {
            return false;
        }
        if (Artisan::call('down') !== 0) {
            throw new \RuntimeException(
                'Failed to put Paymenter into deployment maintenance.'
            );
        }

        return true;
    }

    private function leaveMaintenanceMode(bool $enteredMaintenance): void
    {
        if (!$enteredMaintenance) {
            return;
        }
        if (Artisan::call('up') !== 0) {
            throw new \RuntimeException(
                'Paymenter could not leave deployment maintenance. Run '
                . 'php artisan up manually after inspecting the extension state.'
            );
        }
    }

    private function restartQueueWorkers(): void
    {
        if (Artisan::call('queue:restart') !== 0) {
            throw new \RuntimeException(
                'Queue workers could not be signalled to restart.'
            );
        }
    }
}
