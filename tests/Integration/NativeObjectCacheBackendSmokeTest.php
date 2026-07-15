<?php

declare(strict_types=1);

namespace SymPress\Framework\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use SymPress\Framework\ObjectCache\NativeMemcachedObjectCacheAdapter;
use SymPress\Framework\ObjectCache\NativeRedisObjectCacheAdapter;

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
        $this->connect(fn (): bool => $redis->connect('127.0.0.1', 6379, 1.0));
        $cache = new NativeRedisObjectCacheAdapter($redis, 'sympress-smoke-' . bin2hex(random_bytes(6)));

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
        self::assertTrue($memcached->addServer('127.0.0.1', 11211));
        $this->connect(static fn (): bool => $memcached->getVersion() !== false);
        $cache = new NativeMemcachedObjectCacheAdapter(
            $memcached,
            'sympress-smoke-' . bin2hex(random_bytes(6)),
        );

        self::assertTrue($cache->set('count', 1));
        self::assertSame(1, $cache->get('count'));
        self::assertSame(3, $cache->incr('count', 2));
        self::assertSame(0, $cache->decr('count', 9));
        self::assertTrue($cache->clear());
        self::assertFalse($cache->get('count'));

        $memcached->quit();
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
