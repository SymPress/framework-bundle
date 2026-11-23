<?php

declare(strict_types=1);

namespace SymPress\Framework\Tests\ObjectCache;

/**
 * Minimal phpredis stand-in for the native Redis object-cache adapter.
 */
final class InMemoryRedis
{
    /** @var array<string, string> */
    public array $values = [];

    public int $reads = 0;

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
        $this->reads++;

        return $this->values[$key] ?? false;
    }

    /**
     * @param array<int, string> $keys
     * @return array<int, string|false>
     */
    public function mget(array $keys): array
    {
        $this->reads++;

        return array_map(fn (string $key): string|false => $this->values[$key] ?? false, $keys);
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
}
