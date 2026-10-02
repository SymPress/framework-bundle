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
                'APP_PROJECT_DIR',
                'APP_ENV',
            ] as $name
        ) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        }
    }

    public function testNamespaceSeparatesProjectsAndEnvironmentsEvenWithExplicitPrefix(): void
    {
        $_ENV['APP_PROJECT_DIR'] = '/sites/first';
        $_ENV['APP_ENV'] = 'production';
        $_ENV['SYMPRESS_CACHE_PREFIX'] = 'shared-prefix';
        $factory = new CacheConfigFactory();
        $first = $factory->create()->prefix;
        self::assertSame($first, $factory->create()->prefix);
        $_ENV['APP_PROJECT_DIR'] = '/sites/second';
        self::assertNotSame($first, $factory->create()->prefix);
        $_ENV['APP_PROJECT_DIR'] = '/sites/first';
        $_ENV['APP_ENV'] = 'staging';
        self::assertNotSame($first, $factory->create()->prefix);
    }

    public function testWordPressAuthKeyDoesNotEnablePersistentCacheSigning(): void
    {
        $_ENV['AUTH_KEY'] = str_repeat('test-only-auth-key-', 3);
        self::assertNull((new CacheConfigFactory())->create()->secret);
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

    public function testNativeDotenvConfigurationDoesNotExportCredentials(): void
    {
        $_ENV['SYMPRESS_CACHE_DRIVER'] = 'redis';
        $_SERVER['SYMPRESS_CACHE_DSN'] = 'redis://redis:6379';
        $_ENV['SYMPRESS_CACHE_SECRET'] = 'dotenv-canary-secret';
        $_SERVER['SYMPRESS_CACHE_IN_MEMORY'] = 'false';
        $config = (new CacheConfigFactory())->create();
        self::assertSame('redis', $config->driver);
        self::assertSame('redis://redis:6379', $config->dsn);
        self::assertSame('dotenv-canary-secret', $config->secret);
        self::assertFalse($config->inMemory);
        self::assertFalse(getenv('SYMPRESS_CACHE_SECRET'));
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testExplicitConstantsOverrideDotenvAndProcessValues(): void
    {
        define('SYMPRESS_CACHE_DRIVER', 'array');
        $_ENV['SYMPRESS_CACHE_DRIVER'] = 'redis';
        $_SERVER['SYMPRESS_CACHE_DRIVER'] = 'memcached';
        putenv('SYMPRESS_CACHE_DRIVER=filesystem');
        self::assertSame('array', (new CacheConfigFactory())->create()->driver);
    }
}
