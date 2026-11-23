<?php

declare(strict_types=1);

namespace SymPress\Framework\Tests\ObjectCache;

/**
 * Minimal ext-memcached stand-in that records the expiration passed per key.
 */
final class InMemoryMemcached
{
    /** @var array<string, string> */
    public array $values = [];

    public int $reads = 0;

    /** @var array<string, int> */
    public array $expirations = [];

    private int $resultCode = 0;

    public function add(string $key, string $value, int $expiration = 0): bool
    {
        if (array_key_exists($key, $this->values)) {
            $this->resultCode = 14;

            return false;
        }

        return $this->set($key, $value, $expiration);
    }

    public function set(string $key, string $value, int $expiration = 0): bool
    {
        $this->values[$key] = $value;
        $this->expirations[$key] = $expiration;
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
        $this->reads++;

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
        $this->reads++;

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

    public function delete(string $key): bool
    {
        $existed = array_key_exists($key, $this->values);
        unset($this->values[$key], $this->expirations[$key]);

        return $existed;
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
}
