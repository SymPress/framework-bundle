<?php

declare(strict_types=1);

namespace SymPress\Framework\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use SymPress\Framework\ObjectCache\NativeMemcachedObjectCacheAdapter;
use SymPress\Framework\ObjectCache\NativeRedisObjectCacheAdapter;
use SymPress\Framework\ObjectCache\ObjectCacheValueCodec;
use SymPress\Framework\ObjectCache\CacheAdapterFactory;
use SymPress\Framework\ObjectCache\CacheConfig;

#[Group('live-cache')]
final class NativeObjectCacheBackendSmokeTest extends TestCase
{
    protected function setUp(): void
    {
        if (getenv('SYMPRESS_LIVE_CACHE_TESTS') !== '1') {
            self::markTestSkipped('Set SYMPRESS_LIVE_CACHE_TESTS=1 to test live cache backends.');
        }
    }

    public function testRedisProtocolAndAtomicOperations(): void
    {
        self::assertTrue(class_exists(\Redis::class), 'The redis PHP extension is required.');
        $redis = new \Redis();
        $this->connect(fn (): bool => $redis->connect('127.0.0.1', (int) (getenv('SYMPRESS_TEST_REDIS_PORT') ?: 6379), 1.0));
        $cache = new NativeRedisObjectCacheAdapter($redis, 'sympress-smoke-' . bin2hex(random_bytes(6)), new ObjectCacheValueCodec('review-secret'));

        self::assertTrue($cache->set('object', (object) ['date' => new \DateTimeImmutable('2026-10-01')]));
        $decoded = $cache->get('object');
        self::assertInstanceOf(\stdClass::class, $decoded);
        self::assertSame('2026-10-01', $decoded->date->format('Y-m-d'));

        self::assertTrue($cache->set('count', 1));
        self::assertSame(1, $cache->get('count'));
        self::assertSame(3, $cache->incr('count', 2));
        self::assertSame(0, $cache->decr('count', 9));
        self::assertTrue($cache->clear());
        self::assertFalse($cache->get('count'));

        $redis->close();
    }

    public function testMemcachedProtocolAndAtomicOperations(): void
    {
        self::assertTrue(class_exists(\Memcached::class), 'The memcached PHP extension is required.');
        $memcached = new \Memcached();
        self::assertTrue($memcached->addServer('127.0.0.1', (int) (getenv('SYMPRESS_TEST_MEMCACHED_PORT') ?: 11211)));
        $this->connect(static fn (): bool => $memcached->getVersion() !== false);
        $cache = new NativeMemcachedObjectCacheAdapter(
            $memcached,
            'sympress-smoke-' . bin2hex(random_bytes(6)),
            new ObjectCacheValueCodec('review-secret'),
        );

        self::assertTrue($cache->set('object', (object) ['date' => new \DateTimeImmutable('2026-10-01')]));
        $decoded = $cache->get('object');
        self::assertInstanceOf(\stdClass::class, $decoded);
        self::assertSame('2026-10-01', $decoded->date->format('Y-m-d'));

        self::assertTrue($cache->set('count', 1));
        self::assertSame(1, $cache->get('count'));
        self::assertSame(3, $cache->incr('count', 2));
        self::assertSame(0, $cache->decr('count', 9));
        self::assertTrue($cache->clear());
        self::assertFalse($cache->get('count'));

        $memcached->quit();
    }

    public function testFactoriesUseSignedNativeBackendsWithStrongSecrets(): void
    {
        foreach (['redis', 'memcached'] as $driver) {
            $port = $driver === 'redis'
                ? (int) (getenv('SYMPRESS_TEST_REDIS_PORT') ?: 6379)
                : (int) (getenv('SYMPRESS_TEST_MEMCACHED_PORT') ?: 11211);
            $config = new CacheConfig($driver, [], $driver . '://127.0.0.1:' . $port, false, 0, 'sympress-factory-smoke-' . bin2hex(random_bytes(6)), str_repeat('test-only-secret-', 3));
            $factory = new CacheAdapterFactory();
            $backend = $factory->createPersistent($config);
            self::assertInstanceOf($driver === 'redis' ? NativeRedisObjectCacheAdapter::class : NativeMemcachedObjectCacheAdapter::class, $backend);
            self::assertTrue($backend->set('value', (object) ['driver' => $driver]));
            $found = false;
            self::assertEquals((object) ['driver' => $driver], $factory->createPersistent($config)->get('value', $found));
            self::assertTrue($found);
            self::assertTrue($backend->set('counter', 1));
            self::assertSame(3, $backend->incr('counter', 2));
            self::assertSame(0, $backend->decr('counter', 9));
            self::assertTrue($backend->clear());
        }
    }

    /** @param callable(): bool $probe */
    private function connect(callable $probe): void
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            if ($probe()) {
                return;
            }

            usleep(250_000);
        }

        self::fail('Cache backend did not become ready.');
    }
}
