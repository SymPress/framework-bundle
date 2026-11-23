<?php

declare(strict_types=1);

namespace SymPress\Framework\Tests\ObjectCache;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SymPress\Framework\ObjectCache\CacheKeyGenerator;
use SymPress\Framework\ObjectCache\NativeMemcachedObjectCacheAdapter;
use SymPress\Framework\ObjectCache\NativeRedisObjectCacheAdapter;
use SymPress\Framework\ObjectCache\ObjectCacheValueCodec;

final class NativeObjectCacheVersionTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function backends(): iterable
    {
        yield 'redis' => ['redis'];
        yield 'memcached' => ['memcached'];
    }

    #[DataProvider('backends')]
    public function testNamespaceVersionsAreReadOncePerRequest(string $driver): void
    {
        $connection = $this->connection($driver);
        $backend = $this->backend($connection);
        $keys = [];

        for ($i = 0; $i < 50; $i++) {
            $keys[] = (new CacheKeyGenerator())->create('post-' . $i, 'posts');
            self::assertTrue($backend->set($keys[$i], $i));
        }

        $connection->reads = 0;
        self::assertSame(7, $backend->get($keys[7]));
        self::assertSame(1, $connection->reads);

        $connection->reads = 0;
        self::assertSame(range(0, 49), array_values($backend->getMultiple($keys)));
        self::assertSame(1, $connection->reads);
    }

    #[DataProvider('backends')]
    public function testOwnFlushIsVisibleWithinTheRequest(string $driver): void
    {
        $backend = $this->backend($this->connection($driver));
        $key = (new CacheKeyGenerator())->create('1', 'posts');
        self::assertTrue($backend->set($key, 'cached'));

        self::assertTrue($backend->clear(substr($key, 0, -64)));
        self::assertFalse($backend->get($key));
        self::assertTrue($backend->set($key, 'fresh'));
        self::assertTrue($backend->clear());
        self::assertFalse($backend->get($key));
    }

    #[DataProvider('backends')]
    public function testLostVersionKeyDoesNotResurrectEntriesFromBeforeAFlush(string $driver): void
    {
        $connection = $this->connection($driver);
        $writer = $this->backend($connection);
        self::assertTrue($writer->set('option', 'before-flush'));
        self::assertTrue($writer->clear());

        // Simulate LRU eviction of every namespace version key.
        foreach (array_keys($connection->values) as $rawKey) {
            if (str_contains($rawKey, ':version:')) {
                unset($connection->values[$rawKey]);
            }
        }

        $found = null;
        self::assertFalse($this->backend($connection)->get('option', $found));
        self::assertFalse($found);
    }

    private function connection(string $driver): InMemoryRedis|InMemoryMemcached
    {
        return $driver === 'redis' ? new InMemoryRedis() : new InMemoryMemcached();
    }

    private function backend(InMemoryRedis|InMemoryMemcached $connection): NativeRedisObjectCacheAdapter|NativeMemcachedObjectCacheAdapter
    {
        $codec = new ObjectCacheValueCodec('secret');

        return $connection instanceof InMemoryRedis
            ? new NativeRedisObjectCacheAdapter($connection, 'test', $codec)
            : new NativeMemcachedObjectCacheAdapter($connection, 'test', $codec);
    }
}
