<?php

declare(strict_types=1);

namespace SymPress\Framework\ObjectCache;

use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Cache\Adapter\ApcuAdapter;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Adapter\MemcachedAdapter;
use Symfony\Component\Cache\Adapter\NullAdapter;
use Symfony\Component\Cache\Adapter\PdoAdapter;
use Symfony\Component\Cache\Adapter\RedisAdapter;

final class CacheAdapterFactory
{
    public function createPersistent(CacheConfig $config): ObjectCacheBackendInterface
    {
        try {
            return $this->createAdapter($config);
        } catch (\Throwable $exception) {
            $this->reportBackendFailure($config, $exception);

            return new ObjectCacheAdapter(new ArrayAdapter());
        }
    }

    public function createRuntime(): ObjectCacheBackendInterface
    {
        return new ObjectCacheAdapter(new ArrayAdapter());
    }

    private function createAdapter(CacheConfig $config): ObjectCacheBackendInterface
    {
        return match ($config->driver) {
            'apcu' => new ObjectCacheAdapter($this->createApcu($config)),
            'filesystem' => new ObjectCacheAdapter($this->createFilesystem($config)),
            'redis' => $this->createRedis($config),
            'memcached' => $this->createMemcached($config),
            'pdo', 'sqlite' => new ObjectCacheAdapter($this->createPdo($config)),
            'null' => new ObjectCacheAdapter(new NullAdapter()),
            default => new ObjectCacheAdapter(new ArrayAdapter()),
        };
    }

    private function createApcu(CacheConfig $config): AdapterInterface
    {
        if (!ApcuAdapter::isSupported()) {
            return new ArrayAdapter();
        }

        return new ApcuAdapter($config->prefix);
    }

    private function createFilesystem(CacheConfig $config): AdapterInterface
    {
        $directory = $this->stringArg($config, 'directory')
            ?? $this->stringArg($config, 'path')
            ?? $this->defaultCacheDirectory();

        return new FilesystemAdapter($config->prefix, 0, $directory);
    }

    private function createRedis(CacheConfig $config): ObjectCacheBackendInterface
    {
        $dsn = $config->dsn ?? $this->redisDsnFromArgs($config) ?? 'redis://localhost';
        $connection = RedisAdapter::createConnection($dsn, ['lazy' => false]);

        $this->assertRedisConnection($connection);

        return new NativeRedisObjectCacheAdapter($connection, $config->prefix, $this->codec($config));
    }

    private function createMemcached(CacheConfig $config): ObjectCacheBackendInterface
    {
        $servers = $config->driverArgs['servers'] ?? $config->dsn ?? 'memcached://localhost';

        if (
            is_array($servers)
            && count($servers) === 2
            && is_scalar($servers[0] ?? null)
            && is_scalar($servers[1] ?? null)
        ) {
            $servers = [[(string) $servers[0], (int) $servers[1]]];
        }

        return new NativeMemcachedObjectCacheAdapter(MemcachedAdapter::createConnection($servers), $config->prefix, $this->codec($config));
    }

    private function createPdo(CacheConfig $config): AdapterInterface
    {
        $dsn = $config->dsn
            ?? $this->stringArg($config, 'dsn')
            ?? $this->sqliteDsnFromArgs($config)
            ?? sprintf('sqlite:%s/sympress-object-cache.sqlite', $this->defaultCacheDirectory());

        $options = $config->driverArgs['options'] ?? [];

        return new PdoAdapter($dsn, $config->prefix, 0, is_array($options) ? $options : []);
    }

    private function stringArg(CacheConfig $config, string $key): ?string
    {
        $value = $config->driverArgs[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function redisDsnFromArgs(CacheConfig $config): ?string
    {
        $servers = $config->driverArgs['servers'] ?? null;

        if (!is_array($servers)) {
            return null;
        }

        $server = $servers[0] ?? $servers;

        if (is_array($server)) {
            $host = $server['server'] ?? $server['host'] ?? null;
            $port = $server['port'] ?? 6379;

            if (is_scalar($host)) {
                return sprintf('redis://%s:%d', (string) $host, (int) $port);
            }
        }

        return null;
    }

    private function sqliteDsnFromArgs(CacheConfig $config): ?string
    {
        $path = $this->stringArg($config, 'path') ?? $this->stringArg($config, 'file');

        return $path !== null ? 'sqlite:' . $path : null;
    }

    private function defaultCacheDirectory(): string
    {
        if (defined('WP_CONTENT_DIR')) {
            return WP_CONTENT_DIR . '/cache/sympress';
        }

        return sys_get_temp_dir() . '/sympress-cache';
    }

    private function reportBackendFailure(CacheConfig $config, \Throwable $exception): void
    {
        $message = sprintf(
            'SymPress object cache backend "%s" could not be initialized: %s. Falling back to request-local array cache.',
            $config->driver,
            $exception->getMessage(),
        );

        if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) {
            \WP_CLI::warning($message);

            return;
        }

        error_log($message);
    }

    private function codec(CacheConfig $config): ObjectCacheValueCodec
    {
        return new ObjectCacheValueCodec($config->secret);
    }

    private function assertRedisConnection(object $connection): void
    {
        if (
            $connection instanceof \RedisArray
            || $connection instanceof \RedisCluster
            || str_contains($connection::class, 'Cluster')
        ) {
            return;
        }

        if (!method_exists($connection, 'ping')) {
            return;
        }

        try {
            $connection->ping();
        } catch (\Throwable $exception) {
            throw new \RuntimeException(
                sprintf('Redis health check failed: %s', $exception->getMessage()),
                previous: $exception,
            );
        }
    }
}
