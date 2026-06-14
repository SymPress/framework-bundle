<?php

declare(strict_types=1);

namespace SymPress\Framework\ObjectCache;

interface ObjectCacheBackendInterface
{
    public function add(string $key, mixed $data, int $expire = 0): bool;

    /**
     * @param array<string, mixed> $data
     * @return array<string, bool>
     */
    public function addMultiple(array $data, int $expire = 0): array;

    public function set(string $key, mixed $data, int $expire = 0): bool;

    /**
     * @param array<string, mixed> $data
     * @return array<string, bool>
     */
    public function setMultiple(array $data, int $expire = 0): array;

    public function replace(string $key, mixed $data, int $expire = 0): bool;

    public function get(string $key, ?bool &$found = null): mixed;

    /**
     * @param array<int, string> $keys
     * @param array<string, bool>|null $found
     * @return array<string, mixed>
     */
    public function getMultiple(array $keys, ?array &$found = null): array;

    public function incr(string $key, int $offset = 1): int|false;

    public function decr(string $key, int $offset = 1): int|false;

    public function delete(string $key): bool;

    /**
     * @param array<int, string> $keys
     * @return array<string, bool>
     */
    public function deleteMultiple(array $keys): array;

    public function clear(string $prefix = ''): bool;

    public function purge(): bool;

    public function commit(): bool;

    public function cacheHits(): int;

    public function cacheMisses(): int;
}
