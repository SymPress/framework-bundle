<?php

declare(strict_types=1);

namespace SymPress\Framework\Tests\ObjectCache;

use Psr\Cache\CacheItemInterface;
use PHPUnit\Framework\TestCase;
use SymPress\Framework\ObjectCache\CacheKeyGenerator;
use SymPress\Framework\ObjectCache\ObjectCacheAdapter;
use SymPress\Framework\ObjectCache\ObjectCacheProxy;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class ObjectCacheProxyTest extends TestCase
{
    public function testStoresAndRetrievesFalseWithFoundFlag(): void
    {
        $cache = $this->proxy();

        self::assertTrue($cache->set('feature', false, 'flags'));

        $found = null;
        self::assertFalse($cache->get('feature', 'flags', false, $found));
        self::assertTrue($found);
    }

    public function testAddDoesNotOverwriteExistingKey(): void
    {
        $cache = $this->proxy();

        self::assertTrue($cache->add('count', 1, 'stats'));
        self::assertFalse($cache->add('count', 2, 'stats'));
        self::assertSame(1, $cache->get('count', 'stats'));
    }

    public function testCountersFollowWordPressSemantics(): void
    {
        $cache = $this->proxy();

        self::assertFalse($cache->incr('missing', 1, 'stats'));

        self::assertTrue($cache->set('numeric_string', '1', 'stats'));
        self::assertSame(2, $cache->incr('numeric_string', 1, 'stats'));

        self::assertTrue($cache->set('non_numeric', 'value', 'stats'));
        self::assertSame(1, $cache->incr('non_numeric', 1, 'stats'));

        self::assertTrue($cache->set('non_numeric', 'value', 'stats'));
        self::assertSame(0, $cache->decr('non_numeric', 1, 'stats'));
    }

    public function testFlushGroupOnlyClearsSelectedGroup(): void
    {
        $cache = $this->proxy();
        $cache->set('a', 'first', 'one');
        $cache->set('b', 'second', 'two');

        self::assertTrue($cache->flush_group('one'));

        $found = null;
        self::assertFalse($cache->get('a', 'one', false, $found));
        self::assertFalse($found);
        self::assertSame('second', $cache->get('b', 'two'));
    }

    public function testNonPersistentGroupsAreClearedByRuntimeFlush(): void
    {
        $cache = $this->proxy();
        $cache->add_non_persistent_groups(['counts']);
        $cache->set('runtime', 'value', 'counts');
        $cache->set('persistent', 'value', 'posts');

        self::assertTrue($cache->flush_runtime());

        self::assertFalse($cache->get('runtime', 'counts'));
        self::assertSame('value', $cache->get('persistent', 'posts'));
    }

    public function testMultipleOperationsUseBatchAdapterMethods(): void
    {
        $adapter = new class () extends ArrayAdapter {
            public int $getItemsCalls = 0;
            public int $deleteItemsCalls = 0;
            public int $saveDeferredCalls = 0;
            public int $commitCalls = 0;

            public function getItems(array $keys = []): iterable
            {
                $this->getItemsCalls++;

                return parent::getItems($keys);
            }

            public function deleteItems(array $keys): bool
            {
                $this->deleteItemsCalls++;

                return parent::deleteItems($keys);
            }

            public function saveDeferred(CacheItemInterface $item): bool
            {
                $this->saveDeferredCalls++;

                return parent::saveDeferred($item);
            }

            public function commit(): bool
            {
                $this->commitCalls++;

                return parent::commit();
            }
        };
        $cache = new ObjectCacheProxy(
            new ObjectCacheAdapter(new ArrayAdapter()),
            new ObjectCacheAdapter($adapter),
            new CacheKeyGenerator(),
        );

        self::assertSame(['one' => true, 'two' => true], $cache->set_multiple([
            'one' => 'first',
            'two' => 'second',
        ], 'posts'));
        self::assertSame(2, $adapter->saveDeferredCalls);
        self::assertSame(1, $adapter->commitCalls);

        self::assertSame([
            'one' => 'first',
            'two' => 'second',
        ], $cache->get_multiple(['one', 'two'], 'posts', true));
        self::assertSame(1, $adapter->getItemsCalls);

        self::assertSame(['one' => true, 'two' => true], $cache->delete_multiple(['one', 'two'], 'posts'));
        self::assertSame(1, $adapter->deleteItemsCalls);
    }

    public function testDeleteReturnsFalseForMissingKeys(): void
    {
        $cache = $this->proxy();

        self::assertFalse($cache->delete('missing', 'posts'));
        self::assertSame(['missing' => false], $cache->delete_multiple(['missing'], 'posts'));
    }

    public function testAddMultipleDoesNotOverwriteExistingKeys(): void
    {
        $cache = $this->proxy();
        $cache->set('existing', 'old', 'stats');

        self::assertSame([
            'existing' => false,
            'new' => true,
        ], $cache->add_multiple([
            'existing' => 'new',
            'new' => 'value',
        ], 'stats'));

        self::assertSame('old', $cache->get('existing', 'stats'));
        self::assertSame('value', $cache->get('new', 'stats'));
    }

    private function proxy(): ObjectCacheProxy
    {
        return new ObjectCacheProxy(
            new ObjectCacheAdapter(new ArrayAdapter()),
            new ObjectCacheAdapter(new ArrayAdapter()),
            new CacheKeyGenerator(),
        );
    }
}
