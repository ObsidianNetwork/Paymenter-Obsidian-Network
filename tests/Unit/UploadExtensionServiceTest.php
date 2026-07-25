<?php

namespace Tests\Unit;

use App\Services\Extensions\UploadExtensionService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class UploadExtensionServiceTest extends TestCase
{
    private string $fixtureDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixtureDirectory = storage_path(
            'framework/testing/extension-readiness-'.bin2hex(random_bytes(8))
        );
        File::ensureDirectoryExists($this->fixtureDirectory);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->fixtureDirectory);

        parent::tearDown();
    }

    public function test_destination_readiness_hook_runs_without_resolving_loaded_main_class(): void
    {
        File::put(
            $this->fixtureDirectory.'/migration-readiness.php',
            <<<'PHP'
<?php

return static function (): void {
    throw new RuntimeException('new destination readiness hook ran');
};
PHP
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(
            'new destination readiness hook ran'
        );

        $this->invokeReadinessHook();
    }

    public function test_invalid_destination_readiness_contract_fails_closed(): void
    {
        File::put(
            $this->fixtureDirectory.'/migration-readiness.php',
            '<?php return true;'
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(
            'must return a callable or an object with assertReady'
        );

        $this->invokeReadinessHook();
    }

    public function test_extension_without_destination_readiness_hook_remains_supported(): void
    {
        $this->invokeReadinessHook();

        $this->addToAssertionCount(1);
    }

    public function test_installed_version_uses_directory_and_class_segments(): void
    {
        require_once base_path(
            'tests/Fixtures/Extensions/Others/VersionedFixture/'
            .'VersionedFixture.php'
        );

        $method = new \ReflectionMethod(
            UploadExtensionService::class,
            'installedExtensionVersion'
        );
        $method->setAccessible(true);

        $this->assertSame(
            '1.2.3',
            $method->invoke(
                new UploadExtensionService,
                'other',
                'VersionedFixture'
            )
        );
    }

    public function test_pre_schema_failure_restores_files_and_leaves_maintenance(): void
    {
        [$destination, $backup] = $this->activationFixture();

        Artisan::shouldReceive('call')
            ->once()
            ->with('up')
            ->andReturn(0);

        $this->invokeRecovery(
            $destination,
            $backup,
            schemaActivationStarted: false
        );

        $this->assertSame('old', File::get($destination.'/state.txt'));
        $this->assertDirectoryDoesNotExist($backup);
    }

    public function test_post_schema_failure_restores_files_but_keeps_maintenance(): void
    {
        [$destination, $backup] = $this->activationFixture();

        Artisan::shouldReceive('call')->never();

        $this->invokeRecovery(
            $destination,
            $backup,
            schemaActivationStarted: true
        );

        $this->assertSame('old', File::get($destination.'/state.txt'));
        $this->assertDirectoryDoesNotExist($backup);
    }

    private function invokeReadinessHook(): void
    {
        $method = new \ReflectionMethod(
            UploadExtensionService::class,
            'runDestinationReadinessHook'
        );
        $method->setAccessible(true);
        $method->invoke(
            new UploadExtensionService,
            $this->fixtureDirectory
        );
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function activationFixture(): array
    {
        $destination = $this->fixtureDirectory.'/destination';
        $backup = $this->fixtureDirectory.'/backup';
        File::ensureDirectoryExists($destination);
        File::ensureDirectoryExists($backup);
        File::put($destination.'/state.txt', 'new');
        File::put($backup.'/state.txt', 'old');

        return [$destination, $backup];
    }

    private function invokeRecovery(
        string $destination,
        string $backup,
        bool $schemaActivationStarted
    ): void {
        $method = new \ReflectionMethod(
            UploadExtensionService::class,
            'recoverFailedActivation'
        );
        $method->setAccessible(true);
        $method->invoke(
            new UploadExtensionService,
            $destination,
            $backup,
            true,
            $schemaActivationStarted,
            true,
            new \RuntimeException('activation failed')
        );
    }
}
