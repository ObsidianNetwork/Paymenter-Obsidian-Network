<?php

namespace Tests\Unit;

use App\Console\Commands\Extension\Migrate;
use App\Services\Extensions\ExtensionLifecycleGuard;
use Illuminate\Console\Command;
use Mockery;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\TestCase;

class ExtensionMigrateCommandTest extends TestCase
{
    public function test_migration_requires_the_upgrade_lifecycle_guard(): void
    {
        $guard = Mockery::mock(ExtensionLifecycleGuard::class);
        $guard->shouldReceive('assertCanUpgrade')
            ->once()
            ->with('DynamicPterodactyl')
            ->andThrow(new \RuntimeException('maintenance required'));
        $this->app->instance(ExtensionLifecycleGuard::class, $guard);

        $command = $this->app->make(Migrate::class);
        $command->setLaravel($this->app);
        $tester = new CommandTester($command);

        $status = $tester->execute([
            'type' => 'other',
            'name' => 'DynamicPterodactyl',
            '--force' => true,
        ]);

        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString(
            'maintenance required',
            $tester->getDisplay()
        );
    }
}
