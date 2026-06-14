<?php

declare(strict_types=1);

namespace SymPress\Framework\Tests\ObjectCache;

use PHPUnit\Framework\TestCase;
use SymPress\Framework\ObjectCache\CacheConfigFactory;

final class CacheConfigFactoryTest extends TestCase
{
    protected function tearDown(): void
    {
        foreach (
            [
                'SYMPRESS_CACHE_DRIVER',
                'SYMPRESS_CACHE_DSN',
                'SYMPRESS_CACHE_DRIVER_ARGS',
                'SYMPRESS_CACHE_IN_MEMORY',
                'SYMPRESS_CACHE_PURGE_INTERVAL',
                'SYMPRESS_CACHE_PREFIX',
                'SYMPRESS_CACHE_SECRET',
                'APP_SECRET',
                'AUTH_KEY',
            ] as $name
        ) {
            putenv($name);
        }
    }

    public function testCreatesConfigFromSympressEnvironment(): void
    {
        putenv('SYMPRESS_CACHE_DRIVER=redis');
        putenv('SYMPRESS_CACHE_DSN=redis://redis:6379');
        putenv('SYMPRESS_CACHE_IN_MEMORY=false');
        putenv('SYMPRESS_CACHE_PURGE_INTERVAL=60');
        putenv('SYMPRESS_CACHE_SECRET=secret');

        $config = (new CacheConfigFactory())->create();

        self::assertSame('redis', $config->driver);
        self::assertSame('redis://redis:6379', $config->dsn);
        self::assertFalse($config->inMemory);
        self::assertSame(60, $config->purgeInterval);
        self::assertSame('secret', $config->secret);
    }

    public function testMapsSympressDriverAliases(): void
    {
        putenv('SYMPRESS_CACHE_DRIVER=fs');
        putenv('SYMPRESS_CACHE_DRIVER_ARGS=' . json_encode(['path' => '/tmp/cache'], JSON_THROW_ON_ERROR));

        $config = (new CacheConfigFactory())->create();

        self::assertSame('filesystem', $config->driver);
        self::assertSame('/tmp/cache', $config->driverArgs['path']);
    }

    public function testAcceptsBase64EncodedJsonDriverArgs(): void
    {
        putenv('SYMPRESS_CACHE_DRIVER=sqlite');
        putenv('SYMPRESS_CACHE_DRIVER_ARGS=' . base64_encode(json_encode(['file' => '/tmp/cache.sqlite'], JSON_THROW_ON_ERROR)));

        $config = (new CacheConfigFactory())->create();

        self::assertSame('sqlite', $config->driver);
        self::assertSame('/tmp/cache.sqlite', $config->driverArgs['file']);
    }

    public function testFallsBackToApplicationSecret(): void
    {
        putenv('APP_SECRET=application-secret');

        $config = (new CacheConfigFactory())->create();

        self::assertSame('application-secret', $config->secret);
    }
}
