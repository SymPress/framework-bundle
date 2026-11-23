<?php

declare(strict_types=1);

namespace SymPress\Framework\Tests\ObjectCache;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SymPress\Framework\ObjectCache\NativeMemcachedObjectCacheAdapter;
use SymPress\Framework\ObjectCache\NativeRedisObjectCacheAdapter;
use SymPress\Framework\ObjectCache\ObjectCacheValueCodec;

final class NativeObjectCachePayloadTest extends TestCase
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
    public function testUndecodablePayloadIsAMissSoAddCanRefillAfterSecretRotation(string $driver): void
    {
        $connection = $this->connection($driver);
        self::assertTrue($this->backend($connection, 'old-secret')->set('alloptions', ['siteurl' => 'old']));

        $rotated = $this->backend($connection, 'new-secret');
        $found = null;
        self::assertFalse($rotated->get('alloptions', $found));
        self::assertFalse($found);
        self::assertSame(1, $rotated->cacheMisses());
        self::assertTrue($rotated->add('alloptions', ['siteurl' => 'new']));
        self::assertSame(['siteurl' => 'new'], $rotated->get('alloptions'));
    }

    #[DataProvider('backends')]
    public function testUndecodablePayloadsAreMissesInBatchReads(string $driver): void
    {
        $connection = $this->connection($driver);
        self::assertTrue($this->backend($connection, 'old-secret')->set('stale', 'old'));

        $rotated = $this->backend($connection, 'new-secret');
        self::assertTrue($rotated->set('fresh', 'new'));
        $found = [];
        self::assertSame(['stale' => false, 'fresh' => 'new'], $rotated->getMultiple(['stale', 'fresh'], $found));
        self::assertSame(['stale' => false, 'fresh' => true], $found);
        self::assertTrue($rotated->add('stale', 'refilled'));
    }

    #[DataProvider('backends')]
    public function testStoredFalseRemainsAHit(string $driver): void
    {
        $backend = $this->backend($this->connection($driver), 'secret');
        self::assertTrue($backend->set('flag', false));

        $found = null;
        self::assertFalse($backend->get('flag', $found));
        self::assertTrue($found);
        self::assertFalse($backend->add('flag', true));
    }

    private function connection(string $driver): InMemoryRedis|InMemoryMemcached
    {
        return $driver === 'redis' ? new InMemoryRedis() : new InMemoryMemcached();
    }

    private function backend(InMemoryRedis|InMemoryMemcached $connection, string $secret): NativeRedisObjectCacheAdapter|NativeMemcachedObjectCacheAdapter
    {
        $codec = new ObjectCacheValueCodec($secret);

        return $connection instanceof InMemoryRedis
            ? new NativeRedisObjectCacheAdapter($connection, 'test', $codec)
            : new NativeMemcachedObjectCacheAdapter($connection, 'test', $codec);
    }
}
