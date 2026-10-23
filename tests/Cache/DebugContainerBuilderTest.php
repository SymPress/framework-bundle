<?php

declare(strict_types=1);

namespace SymPress\Framework\Tests\Cache;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use SymPress\Framework\Console\Command\DebugContainerBuilderTrait;
use SymPress\Kernel\Bundle\BundleRegistry;
use SymPress\Kernel\Bundle\AbstractBundle;
use SymPress\Kernel\Bundle\BundleMetadata;
use SymPress\Kernel\Kernel\AbstractKernel;
use Symfony\Component\Filesystem\Filesystem;
use SymPress\Kernel\Container;
use SymPress\Kernel\Kernel\KernelInterface;
use Symfony\Component\DependencyInjection\Container as CompiledContainer;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class DebugContainerBuilderTest extends TestCase
{
    #[DataProvider('snapshots')]
    public function testWarmContainerLintUsesCompleteFreshConfiguration(bool $snapshot): void
    {
        $facade = new Container();
        $fresh = new Container();
        $kernel = $this->createMock(KernelInterface::class);
        $kernel->expects(self::once())->method('createContainer')->willReturn($fresh);
        $kernel->expects(self::once())->method('discoverBundles')->willReturn(new BundleRegistry());
        $kernel->expects(self::once())->method('configureContainer')->willReturnCallback(
            static function (ContainerBuilder $builder): array {
                $builder->registerExtension(new class extends \Symfony\Component\DependencyInjection\Extension\Extension {
                    public function getAlias(): string
                    {
                        return 'fixture';
                    }

                    /** @param array<array<string, mixed>> $configs */
                    public function load(array $configs, ContainerBuilder $container): void
                    {
                        $container->register('fixture.iterator', \ArrayObject::class)->setPublic(true);
                    }
                });
                $builder->register('fixture.consumer', \ArrayIterator::class)->setArguments([new \Symfony\Component\DependencyInjection\Reference('fixture.iterator')])->setPublic(true);

                return [];
            },
        );
        $facade->setKernel($kernel);
        $runtime = new CompiledContainer();
        $stem = tempnam(sys_get_temp_dir(), 'sympress-debug-lint-');
        self::assertIsString($stem);
        if ($snapshot) {
            $optimized = new ContainerBuilder();
            $optimized->register('snapshot.stale', \ArrayObject::class);
            file_put_contents($stem . '.ser', serialize($optimized));
            chmod($stem . '.ser', 0640);
            $runtime->setParameter('debug.container.dump', $stem . '.xml');
        }
        $facade->useRuntimeContainer($runtime);
        $probe = new class {
            use DebugContainerBuilderTrait;

            public function inspect(Container $container): ContainerBuilder
            {
                return $this->debugContainerBuilder($container);
            }
        };
        try {
            $builder = $probe->inspect($facade);
            self::assertTrue($builder->hasExtension('fixture'));
            self::assertFalse($builder->hasDefinition('snapshot.stale'));
            $builder->compile();
            self::assertTrue($builder->hasDefinition('fixture.iterator'));
            self::assertSame($runtime, $facade->runtimeContainer());
            self::assertFalse($facade->builder()->hasDefinition('fixture.iterator'));
        } finally {
            unlink($stem);
            if (is_file($stem . '.ser')) {
                unlink($stem . '.ser');
            }
        }
    }

    public function testDiagnosticsPreserveBootedKernelContainerAndBundleShutdown(): void
    {
        $root = sys_get_temp_dir() . '/diagnostic-lifecycle-' . bin2hex(random_bytes(8));
        mkdir($root, 0700, true);
        file_put_contents($root . '/composer.json', '{}');
        $kernel = new class ($root, 'test', false) extends AbstractKernel {
            public function discoverBundles(): BundleRegistry
            {
                return new BundleRegistry();
            }
        };
        $bundle = new class extends AbstractBundle {
            public int $shutdowns = 0;
            public function shutdown(): void
            {
                ++$this->shutdowns;
            }
        };
        $registry = (new BundleRegistry())->add(new BundleMetadata(
            'fixture/lifecycle', 'library', 'fixture', $root, $root . '/composer.json', $bundle,
        ));
        $container = $kernel->createContainer();
        try {
            $files = $kernel->configureContainer($container->builder(), $container, $registry);
            $kernel->createRuntimeContainer($container, $registry, $files);
            $kernel->boot($container, $registry);
            $liveBundles = $kernel->getBundles();
            $runtime = $container->runtimeContainer();
            $probe = new class {
                use DebugContainerBuilderTrait;
                public function inspect(Container $container): ContainerBuilder
                {
                    return $this->debugContainerBuilder($container);
                }
            };

            $builder = $probe->inspect($container);
            $builder->compile();

            self::assertSame($container, $kernel->getContainer());
            self::assertSame($runtime, $container->runtimeContainer());
            self::assertSame($liveBundles, $kernel->getBundles());
            $kernel->shutdown();
            self::assertSame(1, $bundle->shutdowns);
        } finally {
            $kernel->shutdown();
            (new Filesystem())->remove($root);
        }
    }

    /** @return iterable<string, array{bool}> */
    public static function snapshots(): iterable
    {
        yield 'warm facade' => [false];
        yield 'optimized serialized snapshot' => [true];
    }
}
