<?php

declare(strict_types=1);

namespace SymPress\Framework\Tests\Kernel;

use PHPUnit\Framework\TestCase;
use SymPress\Framework\SymPressFrameworkBundle;
use SymPress\Kernel\Bundle\BundleMetadata;
use SymPress\Kernel\Bundle\BundleRegistry;
use SymPress\Kernel\Kernel\AbstractKernel;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

final class SymPressFrameworkBundleTest extends TestCase
{
    private string $cacheDir;
    private mixed $previousSecret;

    protected function setUp(): void
    {
        $this->cacheDir = sprintf('%s/sympress-framework-bundle-%s', sys_get_temp_dir(), uniqid('', true));
        $this->previousSecret = $_ENV['APP_SECRET'] ?? null;
        $_ENV['APP_SECRET'] = str_repeat('test-only-secret-', 3);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->cacheDir);
        if ($this->previousSecret === null) {
            unset($_ENV['APP_SECRET']);
        } else {
            $_ENV['APP_SECRET'] = $this->previousSecret;
        }
    }

    public function testBuildsRuntimeContainerWithSymfonyFrameworkBundleServices(): void
    {
        $projectDir = dirname(__DIR__, 2);
        $kernel = $this->kernel($projectDir);
        $container = $kernel->createContainer();
        $registry = (new BundleRegistry())->add(new BundleMetadata(
            'sympress/framework-bundle',
            'library',
            'framework-bundle',
            $projectDir,
            $projectDir . '/composer.json',
            new SymPressFrameworkBundle(),
        ));

        $loaded = $kernel->configureContainer($container->builder(), $container, $registry);
        $kernel->createRuntimeContainer($container, $registry, $loaded);

        self::assertContains($projectDir . '/Resources/config/packages/framework.php', $loaded);
        self::assertTrue($container->has('request_stack'));
        self::assertTrue($container->has('http_kernel'));
        self::assertTrue($container->has('cache.app'));
    }

    public function testMissingAndShortSecretsUseRequestCacheOnExistingKernelAndStrongValuesPersist(): void
    {
        foreach (['missing', 'short', 'strong'] as $mode) {
            $directory = $this->cacheDir . '/' . $mode;
            $run = new Process([PHP_BINARY, '-d', 'apc.enable_cli=1', dirname(__DIR__) . '/Fixtures/cache-secret-upgrade.php', $directory, $mode]);
            $run->mustRun();
            $first = json_decode($run->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame(['cache.app' => false, 'cache.files' => false, 'cache.sqlite' => false, 'cache.apcu' => false], $first['hits']);
            self::assertTrue($first['system']);
            $run->mustRun();
            $second = json_decode($run->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            foreach (['cache.app', 'cache.files', 'cache.sqlite'] as $pool) {
                self::assertSame($mode === 'strong', $second['hits'][$pool], $mode . '/' . $pool);
            }
            if ($mode !== 'strong') {
                self::assertDirectoryDoesNotExist($directory . '/pools/app');
                self::assertFileDoesNotExist($directory . '/payload.sqlite');
            }
        }
    }

    public function testUnsignedExistingApplicationCacheEntriesAreRejectedBeforeRestoration(): void
    {
        $run = new Process([PHP_BINARY, '-d', 'apc.enable_cli=1', dirname(__DIR__) . '/Fixtures/cache-secret-upgrade.php', $this->cacheDir, 'strong']);
        $run->mustRun();
        $run = new Process([PHP_BINARY, '-d', 'apc.enable_cli=1', dirname(__DIR__) . '/Fixtures/cache-secret-upgrade.php', $this->cacheDir, 'strong', 'poison']);
        $run->mustRun();
        $result = json_decode($run->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['cache.app' => false, 'cache.files' => false, 'cache.sqlite' => false, 'cache.apcu' => false], $result['hits']);
        self::assertTrue($result['apcu_tamper_miss']);
    }

    private function kernel(string $projectDir): AbstractKernel
    {
        return new class ($projectDir, 'test', false, $this->cacheDir) extends AbstractKernel {
            public function __construct(
                string $projectDir,
                string $environment,
                bool $debug,
                private readonly string $testCacheDir,
            ) {
                parent::__construct($projectDir, $environment, $debug);
            }

            public function getCacheDir(): string
            {
                return $this->testCacheDir;
            }
        };
    }
}
