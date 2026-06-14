<?php

declare(strict_types=1);

namespace SymPress\Framework\Tests\ObjectCache;

use PHPUnit\Framework\TestCase;
use SymPress\Framework\ObjectCache\CacheKeyGenerator;
use SymPress\Framework\ObjectCache\NativeMemcachedObjectCacheAdapter;
use SymPress\Framework\ObjectCache\NativeRedisObjectCacheAdapter;

final class NativeObjectCacheAdapterTest extends TestCase
{
    public function testRedisBackendUsesAtomicCounterScriptWithoutCreatingMissingKeys(): void
    {
        $redis = new class () {
            /** @var array<string, string> */
            public array $values = [];
            public int $evalCalls = 0;

            public function set(string $key, string $value, mixed ...$arguments): bool
            {
                $options = is_array($arguments[0] ?? null) ? $arguments[0] : $arguments;
                $normalized = array_map('strtolower', array_map('strval', $options));

                if (in_array('nx', $normalized, true) && array_key_exists($key, $this->values)) {
                    return false;
                }

                if (in_array('xx', $normalized, true) && !array_key_exists($key, $this->values)) {
                    return false;
                }

                $this->values[$key] = $value;

                return true;
            }

            public function setex(string $key, int $ttl, string $value): bool
            {
                $this->values[$key] = $value;

                return true;
            }

            public function get(string $key): string|false
            {
                return $this->values[$key] ?? false;
            }

            /**
             * @param array<int, string> $keys
             * @return array<int, string|false>
             */
            public function mget(array $keys): array
            {
                return array_map(fn (string $key): string|false => $this->get($key), $keys);
            }

            /**
             * @param string|array<int, string> $keys
             */
            public function del(string|array $keys): int
            {
                $count = 0;

                foreach ((array) $keys as $key) {
                    if (array_key_exists($key, $this->values)) {
                        unset($this->values[$key]);
                        $count++;
                    }
                }

                return $count;
            }

            /**
             * @param array<int, string> $arguments
             */
            public function eval(string $script, array $arguments, int $keyCount): mixed
            {
                $this->evalCalls++;
                $key = $arguments[0];

                if ($keyCount === 1 && count($arguments) === 1) {
                    $next = ((int) ($this->values[$key] ?? 1)) + 1;
                    $this->values[$key] = (string) $next;

                    return $next;
                }

                $mode = $arguments[1];
                $offset = (int) $arguments[2];
                $value = $this->values[$key] ?? null;

                if ($value === null) {
                    return [0, 0];
                }

                if (!is_string($value) || !ctype_digit($value)) {
                    return [2, 0];
                }

                $next = (int) $value;
                $next = $mode === 'decr' ? max(0, $next - $offset) : $next + $offset;
                $this->values[$key] = (string) $next;

                return [1, $next];
            }

        };
        $backend = new NativeRedisObjectCacheAdapter($redis, 'test');

        self::assertFalse($backend->incr('missing'));
        self::assertTrue($backend->set('count', 1));
        self::assertSame(2, $backend->incr('count'));
        self::assertSame(0, $backend->decr('count', 5));
        self::assertSame(3, $redis->evalCalls);

        self::assertTrue($backend->set('numeric_string', '1'));
        self::assertSame(2, $backend->incr('numeric_string'));

        self::assertTrue($backend->set('non_numeric', 'value'));
        self::assertSame(1, $backend->incr('non_numeric'));
        self::assertTrue($backend->set('non_numeric', 'value'));
        self::assertSame(0, $backend->decr('non_numeric'));

        self::assertTrue($backend->set('flush_me', 7));
        self::assertSame(7, $backend->get('flush_me'));
        self::assertTrue($backend->clear());
        $found = null;
        self::assertFalse($backend->get('flush_me', $found));
        self::assertFalse($found);
    }

    public function testMemcachedBackendUsesNativeAtomicCountersAndVersionedGroupFlush(): void
    {
        $memcached = new class () {
            /** @var array<string, string> */
            public array $values = [];
            private int $resultCode = 0;

            public function add(string $key, string $value, int $expiration = 0): bool
            {
                if (array_key_exists($key, $this->values)) {
                    $this->resultCode = 14;

                    return false;
                }

                $this->values[$key] = $value;
                $this->resultCode = 0;

                return true;
            }

            public function set(string $key, string $value, int $expiration = 0): bool
            {
                $this->values[$key] = $value;
                $this->resultCode = 0;

                return true;
            }

            public function replace(string $key, string $value, int $expiration = 0): bool
            {
                if (!array_key_exists($key, $this->values)) {
                    $this->resultCode = 16;

                    return false;
                }

                return $this->set($key, $value, $expiration);
            }

            public function get(string $key): string|false
            {
                if (!array_key_exists($key, $this->values)) {
                    $this->resultCode = 16;

                    return false;
                }

                $this->resultCode = 0;

                return $this->values[$key];
            }

            /**
             * @param array<int, string> $keys
             * @return array<string, string>
             */
            public function getMulti(array $keys): array
            {
                return array_intersect_key($this->values, array_fill_keys($keys, true));
            }

            public function increment(string $key, int $offset = 1): int|false
            {
                $value = $this->values[$key] ?? null;

                if (!is_string($value) || !ctype_digit($value)) {
                    $this->resultCode = 16;

                    return false;
                }

                $this->values[$key] = (string) ((int) $value + $offset);
                $this->resultCode = 0;

                return (int) $this->values[$key];
            }

            public function decrement(string $key, int $offset = 1): int|false
            {
                $value = $this->values[$key] ?? null;

                if (!is_string($value) || !ctype_digit($value)) {
                    $this->resultCode = 16;

                    return false;
                }

                $this->values[$key] = (string) max(0, (int) $value - $offset);
                $this->resultCode = 0;

                return (int) $this->values[$key];
            }

            public function delete(string $key): bool
            {
                unset($this->values[$key]);

                return true;
            }

            /**
             * @param array<int, string> $keys
             * @return array<string, bool>
             */
            public function deleteMulti(array $keys): array
            {
                $result = [];

                foreach ($keys as $key) {
                    $result[$key] = $this->delete($key);
                }

                return $result;
            }

            public function getResultCode(): int
            {
                return $this->resultCode;
            }
        };
        $backend = new NativeMemcachedObjectCacheAdapter($memcached, 'test');
        $key = (new CacheKeyGenerator())->create('count', 'stats');
        $groupPrefix = substr($key, 0, -64);

        self::assertFalse($backend->incr($key));
        self::assertTrue($backend->set($key, 1));
        self::assertSame(3, $backend->incr($key, 2));
        self::assertSame(0, $backend->decr($key, 10));

        self::assertTrue($backend->set($key, '1'));
        self::assertSame(2, $backend->incr($key));

        self::assertTrue($backend->set($key, 'value'));
        self::assertSame(1, $backend->incr($key));
        self::assertTrue($backend->set($key, 'value'));
        self::assertSame(0, $backend->decr($key));

        self::assertTrue($backend->set($key, 7));
        self::assertSame(7, $backend->get($key));
        self::assertTrue($backend->clear($groupPrefix));
        $found = null;
        self::assertFalse($backend->get($key, $found));
        self::assertFalse($found);
    }
}
