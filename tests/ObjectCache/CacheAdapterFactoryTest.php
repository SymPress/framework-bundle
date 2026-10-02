<?php

declare(strict_types=1);

namespace SymPress\Framework\Tests\ObjectCache;

use PHPUnit\Framework\TestCase;
use SymPress\Framework\ObjectCache\CacheAdapterFactory;
use SymPress\Framework\ObjectCache\CacheConfig;
use SymPress\Framework\ObjectCache\CacheConfigFactory;
use Symfony\Component\Cache\Adapter\ApcuAdapter;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Adapter\PdoAdapter;
use Symfony\Component\Filesystem\Filesystem;

final class CacheAdapterFactoryTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\DataProvider('persistentDrivers')]
    public function testPersistentSymfonyAdaptersAuthenticateBeforeDeserialization(string $driver): void
    {
        if ($driver === 'apcu' && (!ApcuAdapter::isSupported() || !apcu_enabled())) {
            self::markTestSkipped('APCu requires apc.enable_cli=1 for CLI tests.');
        }
        $directory = sys_get_temp_dir() . '/sympress-signed-cache-' . bin2hex(random_bytes(6));
        mkdir($directory, 0700);
        $prefix = 'test-' . bin2hex(random_bytes(6));
        $dsn = 'sqlite:' . $directory . '/cache.sqlite';
        $config = new CacheConfig($driver, ['directory' => $directory], $dsn, false, 0, $prefix, str_repeat('test-only-secret-', 3));
        $factory = new CacheAdapterFactory();
        $backend = $factory->createPersistent($config);
        try {
            foreach ([false, 17, ['key' => new \DateTimeImmutable('2026-10-02')]] as $value) {
                self::assertTrue($backend->set('value', $value));
                $found = false;
                self::assertEquals($value, $factory->createPersistent($config)->get('value', $found));
                self::assertTrue($found);
            }
            self::assertTrue($backend->set('counter', 4));
            self::assertSame(7, $backend->incr('counter', 3));
            self::assertSame(0, $backend->decr('counter', 9));

            $unsigned = match ($driver) {
                'apcu' => new ApcuAdapter($prefix, marshaller: new \Symfony\Component\Cache\Marshaller\DefaultMarshaller(false)),
                'filesystem' => new FilesystemAdapter($prefix, 0, $directory),
                default => new PdoAdapter($dsn, $prefix),
            };
            $item = $unsigned->getItem('forged');
            $item->set(new AdapterWakeupProbe());
            self::assertTrue($unsigned->save($item));
            $found = true;
            self::assertFalse($factory->createPersistent($config)->get('forged', $found));
            self::assertFalse($found);
            self::assertSame(0, AdapterWakeupProbe::$woken);
        } finally {
            $backend->clear();
            (new Filesystem())->remove($directory);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function persistentDrivers(): iterable
    {
        yield 'filesystem' => ['filesystem'];
        yield 'sqlite' => ['sqlite'];
        yield 'apcu' => ['apcu'];
    }

    public function testPersistentBackendWithoutStrongSecretUsesRequestLocalCache(): void
    {
        $directory = sys_get_temp_dir() . '/sympress-weak-secret-' . bin2hex(random_bytes(6));
        $previousErrorLog = ini_set('error_log', '/dev/null');
        try {
            foreach ([null, 'short'] as $secret) {
                $backend = (new CacheAdapterFactory())->createPersistent(new CacheConfig('filesystem', ['directory' => $directory], null, false, 0, 'test', $secret));
                self::assertTrue($backend->set('key', 'request-local'));
                self::assertSame('request-local', $backend->get('key'));
                self::assertDirectoryDoesNotExist($directory);
            }
        } finally {
            ini_set('error_log', is_string($previousErrorLog) ? $previousErrorLog : '');
        }
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testDefaultFilesystemStorageIsPrivateAndSeparatedAcrossSites(): void
    {
        $_ENV['SYMPRESS_CACHE_DRIVER'] = 'filesystem';
        $_ENV['APP_SECRET'] = str_repeat('test-only-secret-', 3);
        $_ENV['APP_PROJECT_DIR'] = '/sites/cache-test-' . bin2hex(random_bytes(6));
        $firstConfig = (new CacheConfigFactory())->create();
        $_ENV['APP_PROJECT_DIR'] .= '-second';
        $secondConfig = (new CacheConfigFactory())->create();
        $factory = new CacheAdapterFactory();
        $first = $factory->createPersistent($firstConfig);
        $second = $factory->createPersistent($secondConfig);
        $paths = [];
        try {
            foreach ([$first, $second] as $backend) {
                $pool = (new \ReflectionProperty($backend, 'pool'))->getValue($backend);
                self::assertInstanceOf(FilesystemAdapter::class, $pool);
                $directory = (new \ReflectionProperty($pool, 'directory'))->getValue($pool);
                $paths[] = dirname(rtrim($directory, '/'));
                self::assertSame(0700, fileperms(end($paths)) & 0777);
            }
            self::assertNotSame($paths[0], $paths[1]);
            self::assertTrue($first->set('site-value', 'first-site'));
            self::assertFalse($second->get('site-value'));
        } finally {
            (new Filesystem())->remove($paths);
        }
    }

    public function testRedisConnectionFailureFallsBackToArrayBackend(): void
    {
        $previousErrorLog = ini_set('error_log', '/dev/null');

        try {
            $backend = (new CacheAdapterFactory())->createPersistent(new CacheConfig(
                'redis',
                [],
                'redis://127.0.0.1:1?timeout=0.01',
                true,
                0,
                'sympress.test',
                str_repeat('test-only-secret-', 3),
            ));
        } finally {
            ini_set('error_log', is_string($previousErrorLog) ? $previousErrorLog : '');
        }

        self::assertTrue($backend->set('key', 'value'));

        $found = null;
        self::assertSame('value', $backend->get('key', $found));
        self::assertTrue($found);
    }
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testPublicFilesystemPathsFallBackWithoutWritingCacheFiles(): void
    {
        $directory = sys_get_temp_dir() . '/sympress-public-cache-' . bin2hex(random_bytes(4));
        mkdir($directory);
        define('WP_CONTENT_DIR', $directory);
        $link = $directory . '-link';
        symlink($directory, $link);
        $previousErrorLog = ini_set('error_log', '/dev/null');
        try {
            $backend = (new CacheAdapterFactory())->createPersistent(new CacheConfig('filesystem', ['directory' => $directory . '/cache'], null, false, 0, 'test', str_repeat('test-only-secret-', 3)));
            self::assertTrue($backend->set('private', 'sensitive'));
            self::assertDirectoryDoesNotExist($directory . '/cache');
            $linked = (new CacheAdapterFactory())->createPersistent(new CacheConfig('filesystem', ['directory' => $link . '/new-cache'], null, false, 0, 'test', str_repeat('test-only-secret-', 3)));
            self::assertTrue($linked->set('private', 'sensitive'));
            self::assertDirectoryDoesNotExist($directory . '/new-cache');
        } finally {
            ini_set('error_log', is_string($previousErrorLog) ? $previousErrorLog : '');
            unlink($link);
            rmdir($directory);
        }
    }
}

final class AdapterWakeupProbe
{
    public static int $woken = 0;

    public function __wakeup(): void
    {
        self::$woken++;
    }
}
