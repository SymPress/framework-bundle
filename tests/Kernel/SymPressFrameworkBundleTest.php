<?php

declare(strict_types=1);

namespace SymPress\Framework\Tests\Kernel;

use PHPUnit\Framework\TestCase;
use SymPress\Framework\SymPressFrameworkBundle;
use SymPress\Kernel\Bundle\BundleMetadata;
use SymPress\Kernel\Bundle\BundleRegistry;
use SymPress\Kernel\Kernel\AbstractKernel;
use Symfony\Component\Filesystem\Filesystem;

final class SymPressFrameworkBundleTest extends TestCase
{
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->cacheDir = sprintf('%s/sympress-framework-bundle-%s', sys_get_temp_dir(), uniqid('', true));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->cacheDir);
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

        self::assertContains($projectDir . '/config/packages/framework.php', $loaded);
        self::assertTrue($container->has('request_stack'));
        self::assertTrue($container->has('http_kernel'));
        self::assertTrue($container->has('cache.app'));
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
