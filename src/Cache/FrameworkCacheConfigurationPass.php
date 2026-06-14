<?php

declare(strict_types=1);

namespace SymPress\Framework\Cache;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\ChainAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use Symfony\Component\Cache\DependencyInjection\CachePoolPass;
use Symfony\Component\DependencyInjection\Alias;
use Symfony\Component\DependencyInjection\Argument\IteratorArgument;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\NamespacedPoolInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

final class FrameworkCacheConfigurationPass implements CompilerPassInterface
{
    /**
     * @var array<int, string>
     */
    private const array REDIS_TAG_AWARE_ADAPTERS = [
        'cache.adapter.redis_tag_aware',
        'cache.adapter.valkey_tag_aware',
    ];

    /**
     * @var array<string, mixed>
     */
    private const array DEFAULTS = [
        'prefix_seed' => 'sympress.%kernel.project_dir%.%kernel.environment%',
        'app' => 'cache.adapter.filesystem',
        'system' => 'cache.adapter.system',
        'directory' => '%kernel.cache_dir%/pools/app',
        'default_psr6_provider' => null,
        'default_redis_provider' => 'redis://localhost',
        'default_valkey_provider' => 'valkey://localhost',
        'default_memcached_provider' => 'memcached://localhost',
        'default_doctrine_dbal_provider' => 'database_connection',
        'default_pdo_provider' => null,
        'pools' => [],
    ];

    public function process(ContainerBuilder $container): void
    {
        $config = $this->normalizedConfig($container);

        $container->setParameter('cache.prefix.seed', $config['prefix_seed']);
        $container->setParameter(
            'cache.prefix.seed',
            $container->resolveEnvPlaceholders($container->getParameter('cache.prefix.seed'), true),
        );
        $container->getDefinition('cache.adapter.filesystem')->replaceArgument(2, $config['directory']);

        foreach (['psr6', 'redis', 'valkey', 'memcached', 'doctrine_dbal', 'pdo'] as $name) {
            $key = sprintf('default_%s_provider', $name);

            if (!isset($config[$key]) || $config[$key] === '') {
                continue;
            }

            $container->setAlias(
                sprintf('cache.%s', $key),
                new Alias(CachePoolPass::getServiceProvider($container, (string) $config[$key]), false),
            );
        }

        $this->assertReservedPoolsAreNotUserConfigured((array) $config['pools']);

        $pools = [];

        foreach ((array) $config['pools'] as $name => $pool) {
            if (!is_string($name) || $name === '') {
                throw new InvalidArgumentException('Cache pool names must be non-empty strings.');
            }

            $pools[$name] = $this->normalizePool($pool);
        }

        $pools['cache.app'] = $this->normalizePool([
            'adapters' => [$config['app']],
            'public' => true,
            'tags' => false,
            'clearer' => 'cache.app_clearer',
        ]);
        $pools['cache.system'] = $this->normalizePool([
            'adapters' => [$config['system']],
            'public' => true,
            'tags' => false,
            'clearer' => 'cache.system_clearer',
        ]);

        $registered = [];
        $taggable = [];

        foreach ($pools as $name => $pool) {
            $registration = $this->registerPool($container, $name, $pool, $pools);
            $registered[$registration['name']] = $registration['pool'];

            if ($registration['taggable'] !== null) {
                $taggable[$registration['name']] = $registration['taggable'];
            }
        }

        foreach ($container->findTaggedServiceIds('cache.pool') as $id => $tags) {
            if (!$container->hasDefinition($id) || $container->getDefinition($id)->isAbstract()) {
                continue;
            }

            $poolName = (string) ($tags[0]['name'] ?? $id);
            $registered[$poolName] ??= new Reference($id, ContainerInterface::IGNORE_ON_INVALID_REFERENCE);
        }

        foreach ($container->findTaggedServiceIds('cache.taggable') as $id => $tags) {
            $poolName = (string) ($tags[0]['pool'] ?? $id);
            $taggable[$poolName] ??= new Reference($id, ContainerInterface::IGNORE_ON_INVALID_REFERENCE);
        }

        if ($container->hasDefinition('cache.pool_pruner')) {
            $container->getDefinition('cache.pool_pruner')->replaceArgument(0, new IteratorArgument($registered));
        }

        if ($container->hasDefinition('cache.pool_registry')) {
            $container->getDefinition('cache.pool_registry')->replaceArgument(0, new IteratorArgument($registered));
            $container->getDefinition('cache.pool_registry')->replaceArgument(1, new IteratorArgument($taggable));
            $container->getDefinition('cache.pool_registry')->replaceArgument(2, array_keys($registered));
            $container->getDefinition('cache.pool_registry')->replaceArgument(3, array_keys($taggable));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizedConfig(ContainerBuilder $container): array
    {
        $configured = $this->configuredCache($container);

        $config = array_replace_recursive(self::DEFAULTS, $configured);

        if (!is_array($config['pools'])) {
            throw new InvalidArgumentException('The "framework.cache.pools" value must be an array.');
        }

        return $config;
    }

    /**
     * @return array<string, mixed>
     */
    private function configuredCache(ContainerBuilder $container): array
    {
        $configured = [];

        if ($container->hasParameter('framework')) {
            $framework = $container->getParameter('framework');

            if (!is_array($framework)) {
                throw new InvalidArgumentException('The "framework" parameter must be an array when configured.');
            }

            if (array_key_exists('cache', $framework)) {
                if (!is_array($framework['cache'])) {
                    throw new InvalidArgumentException('The "framework.cache" configuration must be an array.');
                }

                $configured = $framework['cache'];
            }
        }

        if (!$container->hasParameter('framework.cache')) {
            return $configured;
        }

        $cache = $container->getParameter('framework.cache');

        if (!is_array($cache)) {
            throw new InvalidArgumentException('The "framework.cache" parameter must be an array.');
        }

        return array_replace_recursive($configured, $cache);
    }

    /**
     * @param array<string, mixed> $configuredPools
     */
    private function assertReservedPoolsAreNotUserConfigured(array $configuredPools): void
    {
        foreach (['cache.app', 'cache.system'] as $reserved) {
            if (array_key_exists($reserved, $configuredPools)) {
                throw new InvalidArgumentException(sprintf('"%s" is a reserved cache pool name.', $reserved));
            }
        }
    }

    /**
     * @param mixed $pool
     * @return array<string, mixed>
     */
    private function normalizePool(mixed $pool): array
    {
        if (is_string($pool)) {
            $pool = ['adapters' => [$pool]];
        }

        if (!is_array($pool)) {
            throw new InvalidArgumentException('Cache pool configuration must be an array or adapter service id.');
        }

        if (array_key_exists('adapter', $pool) && !array_key_exists('adapters', $pool)) {
            $pool['adapters'] = $pool['adapter'];
        }

        unset($pool['adapter']);

        $adapters = $pool['adapters'] ?? ['cache.app'];

        if (is_string($adapters)) {
            $adapters = [$adapters];
        }

        if (!is_array($adapters)) {
            throw new InvalidArgumentException('Cache pool "adapters" must be a string or array.');
        }

        if ($adapters === []) {
            $adapters = ['cache.app'];
        }

        $normalizedAdapters = [];

        foreach ($adapters as $provider => $adapter) {
            if (is_array($adapter)) {
                $adapterProvider = $adapter['provider'] ?? null;
                $adapter = $adapter['name'] ?? null;

                if (!is_string($adapter) || $adapter === '') {
                    throw new InvalidArgumentException('Array cache pool adapters must define a non-empty "name" value.');
                }

                if ($adapterProvider !== null) {
                    if (!is_string($adapterProvider) || $adapterProvider === '') {
                        throw new InvalidArgumentException('Array cache pool adapter providers must be non-empty service ids.');
                    }

                    $provider = $adapterProvider;
                }
            }

            if (!is_string($adapter) || $adapter === '') {
                throw new InvalidArgumentException('Cache pool adapters must be non-empty service ids.');
            }

            if (!is_int($provider) && $provider === '') {
                throw new InvalidArgumentException('Cache pool adapter providers must be non-empty service ids.');
            }

            if (is_int($provider)) {
                $normalizedAdapters[] = $adapter;

                continue;
            }

            $normalizedAdapters[$provider] = $adapter;
        }

        if (isset($pool['provider']) && count($adapters) > 1) {
            throw new InvalidArgumentException('A cache pool cannot define "provider" when multiple adapters are chained.');
        }

        $pool['adapters'] = $normalizedAdapters;
        $pool['tags'] ??= false;

        if (
            !is_bool($pool['tags'])
            && !is_string($pool['tags'])
        ) {
            throw new InvalidArgumentException('Cache pool "tags" must be a boolean, string service id or null.');
        }

        $pool['public'] = (bool) ($pool['public'] ?? false);

        return $pool;
    }

    /**
     * @param array<string, mixed> $pool
     * @param array<string, array<string, mixed>> $pools
     * @return array{name: string, pool: Reference, taggable: Reference|null}
     */
    private function registerPool(ContainerBuilder $container, string $name, array $pool, array $pools): array
    {
        $adapters = $pool['adapters'];
        $logicalName = (string) ($pool['name'] ?? $name);
        $tagAwareId = sprintf('.%s.taggable', $name);
        $taggableReference = null;
        $isRedisTagAware = $this->isRedisTagAwarePool($pool, $pools);

        foreach ($adapters as $provider => $adapter) {
            if (($pools[$adapter]['tags'] ?? false) && !$this->isRedisTagAwarePool($pools[$adapter], $pools)) {
                $adapters[$provider] = sprintf('.%s.inner', $adapter);
            }
        }

        if (count($adapters) === 1) {
            $provider = array_key_first($adapters);
            $adapter = (string) $adapters[$provider];

            if (!isset($pool['provider']) && !is_int($provider)) {
                $pool['provider'] = (string) $provider;
            }

            $definition = new ChildDefinition($adapter);
        } else {
            $definition = new Definition(ChainAdapter::class, [$adapters, 0]);
            $pool['reset'] = 'reset';
        }

        if ($isRedisTagAware && $name === 'cache.app') {
            $container->setAlias('cache.app.taggable', $name);
            $definition->addTag('cache.taggable', ['pool' => $logicalName]);
            $tagAwareId = $name;
            $taggableReference = new Reference($tagAwareId, ContainerInterface::IGNORE_ON_UNINITIALIZED_REFERENCE);
        } elseif ($isRedisTagAware) {
            $innerId = sprintf('.%s.inner', $name);
            $container->setAlias($innerId, $name);
            $definition->addTag('cache.taggable', ['pool' => $logicalName]);
            $tagAwareId = $name;
            $taggableReference = new Reference($tagAwareId, ContainerInterface::IGNORE_ON_UNINITIALIZED_REFERENCE);
        } elseif (($pool['tags'] ?? false) === true || is_string($pool['tags'] ?? false)) {
            $innerId = sprintf('.%s.inner', $name);
            $tagStoreId = $pool['tags'] === true ? null : (string) $pool['tags'];

            if (
                $tagStoreId !== null
                && ($pools[$tagStoreId]['tags'] ?? false)
                && !$this->isRedisTagAwarePool($pools[$tagStoreId], $pools)
            ) {
                $tagStoreId = sprintf('.%s.inner', $tagStoreId);
            }

            $tagStore = $tagStoreId === null ? null : new Reference($tagStoreId);
            $outerId = $name;

            $container->register($outerId, TagAwareAdapter::class)
                ->addArgument(new Reference($innerId))
                ->addArgument($tagStore)
                ->addMethodCall('setLogger', [new Reference('logger', ContainerInterface::IGNORE_ON_INVALID_REFERENCE)])
                ->setPublic((bool) $pool['public'])
                ->addTag('cache.taggable', ['pool' => $logicalName])
                ->addTag('monolog.logger', ['channel' => 'cache']);

            $pool['name'] ??= $name;
            $pool['public'] = false;
            $name = $innerId;
            $tagAwareId = $outerId;
            $taggableReference = new Reference($tagAwareId, ContainerInterface::IGNORE_ON_UNINITIALIZED_REFERENCE);
        } elseif (!in_array($name, ['cache.app', 'cache.system'], true)) {
            $container->register($tagAwareId, TagAwareAdapter::class)
                ->addArgument(new Reference($name))
                ->addTag('cache.taggable', ['pool' => $logicalName]);
            $taggableReference = new Reference($tagAwareId, ContainerInterface::IGNORE_ON_UNINITIALIZED_REFERENCE);
        } elseif ($name === 'cache.app') {
            $taggableReference = new Reference('cache.app.taggable', ContainerInterface::IGNORE_ON_UNINITIALIZED_REFERENCE);
        }

        if (!in_array($name, ['cache.app', 'cache.system'], true)) {
            $argumentName = (string) ($pool['name'] ?? $name);
            $container->registerAliasForArgument($tagAwareId, TagAwareCacheInterface::class, $argumentName);
            $container->registerAliasForArgument($name, CacheInterface::class, $argumentName);
            $container->registerAliasForArgument($name, CacheItemPoolInterface::class, $argumentName);
            $container->registerAliasForArgument($name, NamespacedPoolInterface::class, $argumentName);
        }

        $definition->setPublic((bool) $pool['public']);
        unset($pool['adapters'], $pool['public'], $pool['tags']);
        $definition->addTag('cache.pool', $pool);
        $container->setDefinition($name, $definition);

        return [
            'name' => $logicalName,
            'pool' => new Reference($name, ContainerInterface::IGNORE_ON_UNINITIALIZED_REFERENCE),
            'taggable' => $taggableReference,
        ];
    }

    /**
     * @param array<string, mixed> $pool
     * @param array<string, array<string, mixed>> $pools
     */
    private function isRedisTagAwarePool(array $pool, array $pools): bool
    {
        if ($this->isRedisTagAwareAdapters($pool['adapters'])) {
            return true;
        }

        foreach ($pool['adapters'] as $adapter) {
            if (
                is_string($adapter)
                && isset($pools[$adapter])
                && $this->isRedisTagAwareAdapters($pools[$adapter]['adapters'])
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int|string, string> $adapters
     */
    private function isRedisTagAwareAdapters(array $adapters): bool
    {
        return count($adapters) === 1
            && in_array((string) reset($adapters), self::REDIS_TAG_AWARE_ADAPTERS, true);
    }
}
