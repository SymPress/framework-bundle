<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Psr\Cache\CacheItemPoolInterface;
use Psr\SimpleCache\CacheInterface as SimpleCacheInterface;
use SymPress\Framework\Admin\CacheFlushController;
use SymPress\Framework\Admin\CacheToolbarMenu;
use SymPress\Framework\Cache\CachePoolClearer;
use SymPress\Framework\Cache\CachePoolPruner;
use SymPress\Framework\Cache\CachePoolRegistry;
use SymPress\Framework\Cache\Command\CachePoolClearCommand;
use SymPress\Framework\Cache\Command\CachePoolDeleteCommand;
use SymPress\Framework\Cache\Command\CachePoolInvalidateTagsCommand;
use SymPress\Framework\Cache\Command\CachePoolListCommand;
use SymPress\Framework\Cache\Command\CachePoolPruneCommand;
use SymPress\Framework\Console\Command\CacheClearCommand;
use SymPress\Framework\Console\Command\CacheWarmupCommand;
use SymPress\Framework\Console\Command\ConfigDebugCommand;
use SymPress\Framework\Console\Command\ConfigDumpReferenceCommand;
use SymPress\Framework\Console\Command\ContainerDebugCommand;
use SymPress\Framework\Console\Command\ContainerLintCommand;
use SymPress\Framework\Console\Command\DebugAutowiringCommand;
use SymPress\Framework\Console\Command\RouterDebugCommand;
use SymPress\Framework\Console\Command\RouterMatchCommand;
use SymPress\Framework\Cli\WpCliRegistrar;
use SymPress\Framework\ObjectCache\DropInInstaller;
use SymPress\Framework\ObjectCache\Hook\DropInInstallerHook;
use SymPress\Framework\ObjectCache\Hook\ScheduledPurgeHook;
use SymPress\Kernel\Container as SymPressContainer;
use SymPress\Kernel\Kernel\KernelInterface;
use Symfony\Component\Cache\Adapter\AbstractAdapter;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Cache\Adapter\ApcuAdapter;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\ChainAdapter;
use Symfony\Component\Cache\Adapter\DoctrineDbalAdapter;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Adapter\MemcachedAdapter;
use Symfony\Component\Cache\Adapter\NullAdapter;
use Symfony\Component\Cache\Adapter\PdoAdapter;
use Symfony\Component\Cache\Adapter\PhpFilesAdapter;
use Symfony\Component\Cache\Adapter\ProxyAdapter;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Cache\Adapter\RedisTagAwareAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use Symfony\Component\Cache\Marshaller\DefaultMarshaller;
use Symfony\Component\Cache\Messenger\EarlyExpirationHandler;
use Symfony\Component\Cache\Psr16Cache;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\NamespacedPoolInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

return static function (ContainerConfigurator $container): void {
    $parameters = $container->parameters();
    $parameters->set('cache.prefix.seed', 'sympress.%kernel.project_dir%.%kernel.environment%');
    $parameters->set('framework.cache.version', '%kernel.environment%');
    $parameters->set('framework.cache', []);

    $services = $container->services();
    $services->defaults()
        ->private()
        ->autowire()
        ->autoconfigure();

    $services->set('cache.app')
        ->parent('cache.adapter.filesystem')
        ->public()
        ->tag('cache.pool', ['clearer' => 'cache.app_clearer']);

    $services->set('cache.app.taggable', TagAwareAdapter::class)
        ->args([service('cache.app')])
        ->tag('cache.taggable', ['pool' => 'cache.app']);

    $services->set('cache.system')
        ->parent('cache.adapter.system')
        ->public()
        ->tag('cache.pool', ['clearer' => 'cache.system_clearer']);

    $services->set('cache.validator')
        ->parent('cache.system')
        ->tag('cache.pool');

    $services->set('cache.serializer')
        ->parent('cache.system')
        ->tag('cache.pool');

    $services->set('cache.property_info')
        ->parent('cache.system')
        ->tag('cache.pool');

    $services->set('cache.asset_mapper')
        ->parent('cache.system')
        ->tag('cache.pool');

    $services->set('cache.messenger.restart_workers_signal')
        ->parent('cache.app')
        ->tag('cache.pool');

    $services->set('cache.scheduler')
        ->parent('cache.app')
        ->tag('cache.pool');

    $services->set('cache.adapter.system', AdapterInterface::class)
        ->abstract()
        ->factory([AbstractAdapter::class, 'createSystemCache'])
        ->args([
            '',
            0,
            param('framework.cache.version'),
            '%kernel.cache_dir%/pools/system',
            service('logger')->ignoreOnInvalid(),
        ])
        ->tag('cache.pool', ['clearer' => 'cache.system_clearer', 'reset' => 'reset'])
        ->tag('monolog.logger', ['channel' => 'cache']);

    $services->set('cache.adapter.apcu', ApcuAdapter::class)
        ->abstract()
        ->args([
            '',
            0,
            param('framework.cache.version'),
            service('cache.default_marshaller')->ignoreOnInvalid(),
        ])
        ->call('setLogger', [service('logger')->ignoreOnInvalid()])
        ->tag('cache.pool', ['clearer' => 'cache.default_clearer', 'reset' => 'reset'])
        ->tag('monolog.logger', ['channel' => 'cache']);

    $services->set('cache.adapter.filesystem', FilesystemAdapter::class)
        ->abstract()
        ->args([
            '',
            0,
            '%kernel.cache_dir%/pools/app',
            service('cache.default_marshaller')->ignoreOnInvalid(),
        ])
        ->call('setLogger', [service('logger')->ignoreOnInvalid()])
        ->tag('cache.pool', ['clearer' => 'cache.default_clearer', 'reset' => 'reset'])
        ->tag('monolog.logger', ['channel' => 'cache']);

    $services->set('cache.adapter.php_files', PhpFilesAdapter::class)
        ->abstract()
        ->args([
            '',
            0,
            '%kernel.cache_dir%/pools/php',
            service('cache.default_marshaller')->ignoreOnInvalid(),
        ])
        ->call('setLogger', [service('logger')->ignoreOnInvalid()])
        ->tag('cache.pool', ['clearer' => 'cache.default_clearer', 'reset' => 'reset'])
        ->tag('monolog.logger', ['channel' => 'cache']);

    $services->set('cache.adapter.array', ArrayAdapter::class)
        ->abstract()
        ->args([0])
        ->call('setLogger', [service('logger')->ignoreOnInvalid()])
        ->tag('cache.pool', ['clearer' => 'cache.default_clearer', 'reset' => 'reset'])
        ->tag('monolog.logger', ['channel' => 'cache']);

    $services->set('cache.adapter.null', NullAdapter::class)
        ->abstract()
        ->tag('cache.pool', ['clearer' => 'cache.default_clearer']);

    $services->set('cache.adapter.chain', ChainAdapter::class)
        ->abstract()
        ->args([[], 0])
        ->tag('cache.pool', ['clearer' => 'cache.default_clearer', 'reset' => 'reset']);

    $services->set('cache.adapter.psr6', ProxyAdapter::class)
        ->abstract()
        ->args([
            abstract_arg('PSR-6 provider service'),
            '',
            0,
        ])
        ->tag('cache.pool', [
            'provider' => 'cache.default_psr6_provider',
            'clearer' => 'cache.default_clearer',
            'reset' => 'reset',
        ]);

    $services->set('cache.adapter.redis', RedisAdapter::class)
        ->abstract()
        ->args([
            abstract_arg('Redis connection service'),
            '',
            0,
            service('cache.default_marshaller')->ignoreOnInvalid(),
        ])
        ->call('setLogger', [service('logger')->ignoreOnInvalid()])
        ->tag('cache.pool', [
            'provider' => 'cache.default_redis_provider',
            'clearer' => 'cache.default_clearer',
            'reset' => 'reset',
        ])
        ->tag('monolog.logger', ['channel' => 'cache']);

    $services->set('cache.adapter.redis_tag_aware', RedisTagAwareAdapter::class)
        ->abstract()
        ->args([
            abstract_arg('Redis connection service'),
            '',
            0,
            service('cache.default_marshaller')->ignoreOnInvalid(),
        ])
        ->call('setLogger', [service('logger')->ignoreOnInvalid()])
        ->tag('cache.pool', [
            'provider' => 'cache.default_redis_provider',
            'clearer' => 'cache.default_clearer',
            'reset' => 'reset',
        ])
        ->tag('monolog.logger', ['channel' => 'cache']);

    $services->alias('cache.adapter.valkey', 'cache.adapter.redis');
    $services->alias('cache.adapter.valkey_tag_aware', 'cache.adapter.redis_tag_aware');

    $services->set('cache.adapter.memcached', MemcachedAdapter::class)
        ->abstract()
        ->args([
            abstract_arg('Memcached connection service'),
            '',
            0,
            service('cache.default_marshaller')->ignoreOnInvalid(),
        ])
        ->call('setLogger', [service('logger')->ignoreOnInvalid()])
        ->tag('cache.pool', [
            'provider' => 'cache.default_memcached_provider',
            'clearer' => 'cache.default_clearer',
            'reset' => 'reset',
        ])
        ->tag('monolog.logger', ['channel' => 'cache']);

    $services->set('cache.adapter.doctrine_dbal', DoctrineDbalAdapter::class)
        ->abstract()
        ->args([
            abstract_arg('DBAL connection service'),
            '',
            0,
            [],
            service('cache.default_marshaller')->ignoreOnInvalid(),
        ])
        ->call('setLogger', [service('logger')->ignoreOnInvalid()])
        ->tag('cache.pool', [
            'provider' => 'cache.default_doctrine_dbal_provider',
            'clearer' => 'cache.default_clearer',
            'reset' => 'reset',
        ])
        ->tag('monolog.logger', ['channel' => 'cache']);

    $services->set('cache.adapter.pdo', PdoAdapter::class)
        ->abstract()
        ->args([
            abstract_arg('PDO connection service'),
            '',
            0,
            [],
            service('cache.default_marshaller')->ignoreOnInvalid(),
        ])
        ->call('setLogger', [service('logger')->ignoreOnInvalid()])
        ->tag('cache.pool', [
            'provider' => 'cache.default_pdo_provider',
            'clearer' => 'cache.default_clearer',
            'reset' => 'reset',
        ])
        ->tag('monolog.logger', ['channel' => 'cache']);

    $services->set('cache.default_marshaller', DefaultMarshaller::class)
        ->args([null, '%kernel.debug%']);

    $services->set('cache.early_expiration_handler', EarlyExpirationHandler::class)
        ->args([service('reverse_container')])
        ->tag('messenger.message_handler');

    $services->set('cache.default_clearer', CachePoolClearer::class)
        ->args([[]]);

    $services->set('cache.system_clearer')
        ->parent('cache.default_clearer')
        ->public();

    $services->set('cache.global_clearer')
        ->parent('cache.default_clearer')
        ->public();

    $services->alias('cache.app_clearer', 'cache.default_clearer')
        ->public();

    $services->set('cache.pool_pruner', CachePoolPruner::class)
        ->args([tagged_iterator('cache.pool_pruner')])
        ->public();

    $services->set('cache.pool_registry', CachePoolRegistry::class)
        ->args([[], [], [], []])
        ->public();

    $services->set(CachePoolListCommand::class)
        ->args([service('cache.pool_registry')])
        ->tag('console.command');

    $services->set(CachePoolClearCommand::class)
        ->args([service('cache.pool_registry')])
        ->tag('console.command');

    $services->set(CachePoolPruneCommand::class)
        ->args([service('cache.pool_registry')])
        ->tag('console.command');

    $services->set(CachePoolDeleteCommand::class)
        ->args([service('cache.pool_registry')])
        ->tag('console.command');

    $services->set(CachePoolInvalidateTagsCommand::class)
        ->args([service('cache.pool_registry')])
        ->tag('console.command');

    $services->set('console.command.cache_warmup', CacheWarmupCommand::class)
        ->args([
            service(KernelInterface::class),
            service('cache_warmer')->nullOnInvalid(),
            service('filesystem'),
        ])
        ->tag('console.command');

    $services->set('console.command.cache_clear', CacheClearCommand::class)
        ->args([
            service(KernelInterface::class),
            service('cache_clearer')->nullOnInvalid(),
            service('cache_warmer')->nullOnInvalid(),
            service('filesystem'),
        ])
        ->tag('console.command');

    $services->set('console.command.config_debug', ConfigDebugCommand::class)
        ->args([
            service(KernelInterface::class),
            service(SymPressContainer::class),
        ])
        ->tag('console.command');

    $services->set('console.command.config_dump_reference', ConfigDumpReferenceCommand::class)
        ->args([
            service(KernelInterface::class),
            service(SymPressContainer::class),
        ])
        ->tag('console.command');

    $services->set('console.command.container_debug', ContainerDebugCommand::class)
        ->args([service(SymPressContainer::class)])
        ->tag('console.command');

    $services->set('console.command.container_lint', ContainerLintCommand::class)
        ->args([service(SymPressContainer::class)])
        ->tag('console.command');

    $services->set('console.command.debug_autowiring', DebugAutowiringCommand::class)
        ->args([
            service(SymPressContainer::class),
            service('debug.file_link_formatter')->nullOnInvalid(),
        ])
        ->tag('console.command');

    $services->set('console.command.router_debug', RouterDebugCommand::class)
        ->args([
            service('router')->nullOnInvalid(),
            service('debug.file_link_formatter')->nullOnInvalid(),
        ])
        ->tag('console.command');

    $services->set('console.command.router_match', RouterMatchCommand::class)
        ->args([
            service('router')->nullOnInvalid(),
            tagged_iterator('routing.expression_language_provider'),
        ])
        ->tag('console.command');

    $services->set('cache.simple', Psr16Cache::class)
        ->args([service('cache.app')])
        ->public();

    $services->alias(CacheItemPoolInterface::class, 'cache.app')
        ->public();
    $services->alias(CacheInterface::class, 'cache.app')
        ->public();
    $services->alias(NamespacedPoolInterface::class, 'cache.app')
        ->public();
    $services->alias(TagAwareCacheInterface::class, 'cache.app.taggable')
        ->public();
    $services->alias(SimpleCacheInterface::class, 'cache.simple')
        ->public();

    $services->set(DropInInstaller::class)
        ->args(['%kernel.project_dir%', '%wordpress.content_dir%'])
        ->public();

    $services->set(DropInInstallerHook::class)
        ->tag('kernel.hook', ['hook' => 'init', 'method' => 'install', 'priority' => 1]);

    $services->set(ScheduledPurgeHook::class)
        ->tag('kernel.hook', ['hook' => 'init', 'method' => 'schedule', 'priority' => 20])
        ->tag('kernel.hook', ['hook' => ScheduledPurgeHook::HOOK, 'method' => 'purge']);

    $services->set(CacheToolbarMenu::class)
        ->tag('kernel.hook', ['hook' => 'admin_bar_menu', 'method' => 'render', 'priority' => 80]);

    $services->set(CacheFlushController::class)
        ->tag('kernel.hook', ['hook' => 'admin_post_sympress_framework_flush_cache', 'method' => 'flush']);

    $services->set(WpCliRegistrar::class)
        ->tag('kernel.hook', ['hook' => 'cli_init', 'method' => 'register']);
};
