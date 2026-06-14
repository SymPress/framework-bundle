<?php

declare(strict_types=1);

namespace SymPress\Framework\Tests\Cache;

use PHPUnit\Framework\TestCase;
use SymPress\Framework\Cache\FrameworkCacheConfigurationPass;
use Symfony\Component\Cache\Adapter\ChainAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use Symfony\Component\Cache\DependencyInjection\CachePoolPass;
use Symfony\Component\DependencyInjection\Argument\IteratorArgument;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

final class FrameworkCacheConfigurationPassTest extends TestCase
{
    public function testRegistersDefaultAndCustomCachePools(): void
    {
        $container = $this->container();
        $container->setParameter('framework.cache', [
            'pools' => [
                'cache.marketing' => [
                    'adapters' => ['cache.adapter.array'],
                    'default_lifetime' => 300,
                    'public' => true,
                    'tags' => true,
                ],
            ],
        ]);

        (new FrameworkCacheConfigurationPass())->process($container);
        (new CachePoolPass())->process($container);

        self::assertTrue($container->hasDefinition('cache.app'));
        self::assertTrue($container->hasDefinition('cache.system'));
        self::assertTrue($container->hasDefinition('cache.marketing'));
        self::assertTrue($container->getDefinition('cache.marketing')->isPublic());
    }

    public function testRegistersFrameworkBundleCachePools(): void
    {
        $container = $this->container();

        (new FrameworkCacheConfigurationPass())->process($container);

        foreach (
            [
                'cache.validator',
                'cache.serializer',
                'cache.property_info',
                'cache.asset_mapper',
                'cache.messenger.restart_workers_signal',
                'cache.scheduler',
            ] as $pool
        ) {
            self::assertTrue($container->hasDefinition($pool), sprintf('Missing "%s".', $pool));
        }

        $registryPools = $container->getDefinition('cache.pool_registry')->getArgument(0);

        self::assertInstanceOf(IteratorArgument::class, $registryPools);
        self::assertArrayHasKey('cache.validator', $registryPools->getValues());
        self::assertArrayHasKey('cache.scheduler', $registryPools->getValues());
    }

    public function testSupportsSingularAdapterAndProviderAdapterEntries(): void
    {
        $container = $this->container();
        $container->setParameter('framework.cache', [
            'pools' => [
                'cache.redis_pool' => [
                    'adapter' => 'cache.adapter.redis',
                    'provider' => 'redis://cache',
                ],
                'cache.chain_pool' => [
                    'adapters' => [
                        ['name' => 'cache.adapter.redis', 'provider' => 'redis://one'],
                        'cache.adapter.array',
                    ],
                ],
            ],
        ]);

        (new FrameworkCacheConfigurationPass())->process($container);

        $redisPool = $container->getDefinition('cache.redis_pool');
        self::assertInstanceOf(ChildDefinition::class, $redisPool);
        self::assertSame('cache.adapter.redis', $redisPool->getParent());
        self::assertSame('redis://cache', $redisPool->getTag('cache.pool')[0]['provider']);

        $chainPool = $container->getDefinition('cache.chain_pool');
        self::assertSame(ChainAdapter::class, $chainPool->getClass());
        self::assertSame(
            ['redis://one' => 'cache.adapter.redis', 'cache.adapter.array'],
            $chainPool->getArgument(0),
        );
    }

    public function testSupportsSymfonyStyleFrameworkCacheParameter(): void
    {
        $container = $this->container();
        $container->setParameter('framework', [
            'cache' => [
                'app' => 'cache.adapter.array',
                'pools' => [
                    'cache.symfony_style' => [
                        'adapter' => 'cache.adapter.filesystem',
                        'default_lifetime' => 'PT5M',
                        'namespace' => 'symfony_style',
                        'marshaller' => 'cache.default_marshaller',
                        'clearer' => 'cache.system_clearer',
                        'early_expiration_message_bus' => 'messenger.default_bus',
                    ],
                ],
            ],
        ]);

        (new FrameworkCacheConfigurationPass())->process($container);

        $appPool = $container->getDefinition('cache.app');
        self::assertInstanceOf(ChildDefinition::class, $appPool);
        self::assertSame('cache.adapter.array', $appPool->getParent());

        $poolTag = $container->getDefinition('cache.symfony_style')->getTag('cache.pool')[0];
        self::assertSame('PT5M', $poolTag['default_lifetime']);
        self::assertSame('symfony_style', $poolTag['namespace']);
        self::assertSame('cache.default_marshaller', $poolTag['marshaller']);
        self::assertSame('cache.system_clearer', $poolTag['clearer']);
        self::assertSame('messenger.default_bus', $poolTag['early_expiration_message_bus']);
    }

    public function testFrameworkCacheParameterOverridesNestedFrameworkCacheConfiguration(): void
    {
        $container = $this->container();
        $container->setParameter('framework', [
            'cache' => [
                'app' => 'cache.adapter.filesystem',
            ],
        ]);
        $container->setParameter('framework.cache', [
            'app' => 'cache.adapter.array',
        ]);

        (new FrameworkCacheConfigurationPass())->process($container);

        $appPool = $container->getDefinition('cache.app');
        self::assertInstanceOf(ChildDefinition::class, $appPool);
        self::assertSame('cache.adapter.array', $appPool->getParent());
    }

    public function testRegistersRedisTagAwarePoolsWithoutWrappingThem(): void
    {
        $container = $this->container();
        $container->setParameter('framework.cache', [
            'app' => 'cache.adapter.redis_tag_aware',
            'pools' => [
                'cache.redis_tags' => [
                    'adapter' => 'cache.adapter.redis_tag_aware',
                ],
            ],
        ]);

        (new FrameworkCacheConfigurationPass())->process($container);

        self::assertTrue($container->hasAlias('cache.app.taggable'));
        self::assertSame('cache.app', (string) $container->getAlias('cache.app.taggable'));
        self::assertSame([['pool' => 'cache.app']], $container->getDefinition('cache.app')->getTag('cache.taggable'));

        $redisTags = $container->getDefinition('cache.redis_tags');
        self::assertInstanceOf(ChildDefinition::class, $redisTags);
        self::assertSame('cache.adapter.redis_tag_aware', $redisTags->getParent());
        self::assertSame([['pool' => 'cache.redis_tags']], $redisTags->getTag('cache.taggable'));

        $taggablePools = $container->getDefinition('cache.pool_registry')->getArgument(1);
        self::assertInstanceOf(IteratorArgument::class, $taggablePools);
        self::assertSame('cache.redis_tags', (string) $taggablePools->getValues()['cache.redis_tags']);
    }

    public function testPoolsReferenceInnerPoolsWhenTheyBuildOnTaggedPools(): void
    {
        $container = $this->container();
        $container->setParameter('framework.cache', [
            'pools' => [
                'cache.store' => [
                    'adapter' => 'cache.adapter.array',
                    'tags' => true,
                ],
                'cache.consumer' => [
                    'adapter' => 'cache.store',
                ],
                'cache.tagged_with_store' => [
                    'adapter' => 'cache.adapter.array',
                    'tags' => 'cache.store',
                ],
            ],
        ]);

        (new FrameworkCacheConfigurationPass())->process($container);

        $consumer = $container->getDefinition('cache.consumer');
        self::assertInstanceOf(ChildDefinition::class, $consumer);
        self::assertSame('.cache.store.inner', $consumer->getParent());

        $taggedWithStore = $container->getDefinition('cache.tagged_with_store');
        self::assertSame(TagAwareAdapter::class, $taggedWithStore->getClass());
        self::assertSame('.cache.store.inner', (string) $taggedWithStore->getArgument(1));

        $taggablePools = $container->getDefinition('cache.pool_registry')->getArgument(1);
        self::assertInstanceOf(IteratorArgument::class, $taggablePools);
        self::assertSame('cache.store', (string) $taggablePools->getValues()['cache.store']);
    }

    private function container(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.cache_dir', sys_get_temp_dir() . '/sympress-framework-cache-test');
        $container->setParameter('kernel.debug', false);
        $container->setParameter('kernel.project_dir', dirname(__DIR__, 2));
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('wordpress.content_dir', null);

        $loader = new PhpFileLoader($container, new FileLocator(dirname(__DIR__, 2) . '/config'));
        $loader->load('services.php');

        return $container;
    }
}
