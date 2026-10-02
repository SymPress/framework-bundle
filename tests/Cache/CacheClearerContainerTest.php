<?php

declare(strict_types=1);

namespace SymPress\Framework\Tests\Cache;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SymPress\Framework\Cache\CachePoolClearer;
use SymPress\Framework\Console\Command\ContainerLintCommand;
use SymPress\Framework\SymPressFrameworkBundle;
use SymPress\Kernel\Bundle\BundleMetadata;
use SymPress\Kernel\Bundle\BundleRegistry;
use SymPress\Kernel\Kernel\AbstractKernel;
use Symfony\Bundle\FrameworkBundle\CacheWarmer\CachePoolClearerCacheWarmer;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\CacheClearer\Psr6CacheClearer;

final class CacheClearerContainerTest extends TestCase
{
    public static function environments(): iterable
    {
        yield 'production' => [false];
        yield 'development' => [true];
    }

    #[DataProvider('environments')]
    public function testNativeClearersAndDebugWarmerSurviveRuntimeCompilationAndFullLint(bool $debug): void
    {
        $projectDir = dirname(__DIR__, 2);
        $cacheDir = sys_get_temp_dir() . '/sympress-clearer-' . bin2hex(random_bytes(8));
        $kernel = new class ($projectDir, $debug, $cacheDir) extends AbstractKernel {
            public function __construct(string $projectDir, bool $debug, private readonly string $cacheDir)
            {
                parent::__construct($projectDir, $debug ? 'dev' : 'prod', $debug);
            }

            public function getCacheDir(): string
            {
                return $this->cacheDir;
            }
        };
        try {
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
            $container->builder()->setAlias('test.custom_clearer', CachePoolClearer::class)->setPublic(true);
            if ($debug) {
                $container->builder()->setAlias('test.debug_warmer', 'cache_pool_clearer.cache_warmer')->setPublic(true);
            }
            $kernel->createRuntimeContainer($container, $registry, $loaded);

            $tester = new CommandTester(new ContainerLintCommand($container));
            self::assertSame(Command::SUCCESS, $tester->execute([]), $tester->getDisplay());
            $system = $container->get('cache.system_clearer');
            $app = $container->get('cache.app_clearer');
            $global = $container->get('cache.global_clearer');
            self::assertInstanceOf(Psr6CacheClearer::class, $system);
            self::assertInstanceOf(Psr6CacheClearer::class, $app);
            self::assertInstanceOf(Psr6CacheClearer::class, $global);
            self::assertTrue($system->hasPool('cache.validator'));
            self::assertFalse($system->hasPool('cache.app'));
            self::assertTrue($app->hasPool('cache.app'));
            self::assertTrue($global->hasPool('cache.validator'));
            self::assertTrue($global->hasPool('cache.app'));

            $validatorPool = $system->getPool('cache.validator');
            $serializerPool = $system->getPool('cache.serializer');
            $appPool = $app->getPool('cache.app');
            foreach ([$validatorPool, $serializerPool, $appPool] as $pool) {
                self::assertTrue($pool->save($pool->getItem('probe')->set('cached')));
            }
            if ($debug) {
                $warmer = $container->get('test.debug_warmer');
                self::assertInstanceOf(CachePoolClearerCacheWarmer::class, $warmer);
                self::assertSame([], $warmer->warmUp($cacheDir));
                self::assertFalse($validatorPool->hasItem('probe'));
                self::assertFalse($serializerPool->hasItem('probe'));
                self::assertTrue($appPool->hasItem('probe'));
            }

            $custom = $container->get('test.custom_clearer');
            self::assertInstanceOf(CachePoolClearer::class, $custom);
            self::assertTrue($validatorPool->save($validatorPool->getItem('probe')->set('cached')));
            self::assertTrue($custom->clear(['cache.app']));
            self::assertFalse($appPool->hasItem('probe'));
            self::assertTrue($validatorPool->hasItem('probe'));
            self::assertTrue($custom->clear());
            self::assertFalse($validatorPool->hasItem('probe'));
            self::assertFalse($serializerPool->hasItem('probe'));

            self::assertTrue($appPool->save($appPool->getItem('probe')->set('cached')));
            self::assertTrue($validatorPool->save($validatorPool->getItem('probe')->set('cached')));
            $system->clear($cacheDir);
            self::assertFalse($validatorPool->hasItem('probe'));
            self::assertTrue($appPool->hasItem('probe'));
            $global->clear($cacheDir);
            self::assertFalse($appPool->hasItem('probe'));
        } finally {
            (new Filesystem())->remove($cacheDir);
        }
    }
}
