<?php

declare(strict_types=1);

namespace SymPress\Framework\ObjectCache;

final class NativeMemcachedObjectCacheAdapter implements ObjectCacheBackendInterface
{
    private ObjectCacheValueCodec $codec;
    private int $cacheHits = 0;
    private int $cacheMisses = 0;

    public function __construct(
        private readonly object $memcached,
        private readonly string $prefix,
        ?ObjectCacheValueCodec $codec = null,
    ) {
        $this->codec = $codec ?? new ObjectCacheValueCodec();
    }

    public function add(string $key, mixed $data, int $expire = 0): bool
    {
        return (bool) $this->memcached->add($this->rawKey($key), $this->codec->encode($data), $expire);
    }

    public function addMultiple(array $data, int $expire = 0): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            $result[$key] = $this->add((string) $key, $value, $expire);
        }

        return $result;
    }

    public function set(string $key, mixed $data, int $expire = 0): bool
    {
        return (bool) $this->memcached->set($this->rawKey($key), $this->codec->encode($data), $expire);
    }

    public function setMultiple(array $data, int $expire = 0): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            $result[$key] = $this->set((string) $key, $value, $expire);
        }

        return $result;
    }

    public function replace(string $key, mixed $data, int $expire = 0): bool
    {
        return (bool) $this->memcached->replace($this->rawKey($key), $this->codec->encode($data), $expire);
    }

    /**
     * @param-out bool $found
     */
    public function get(string $key, ?bool &$found = null): mixed
    {
        $payload = $this->memcached->get($this->rawKey($key));

        if ($payload === false && $this->lastResultCode() === $this->notFoundCode()) {
            $this->cacheMisses++;
            $found = false;

            return false;
        }

        $this->cacheHits++;
        $found = true;

        return $this->codec->decode($payload);
    }

    /**
     * @param-out array<string, bool> $found
     */
    public function getMultiple(array $keys, ?array &$found = null): array
    {
        $rawKeys = [];
        $keyMap = [];

        foreach ($keys as $key) {
            $rawKey = $this->rawKey($key);
            $rawKeys[] = $rawKey;
            $keyMap[$rawKey] = $key;
        }

        $payloads = $this->memcached->getMulti($rawKeys);
        $payloads = is_array($payloads) ? $payloads : [];
        $result = [];
        $found = [];

        foreach ($rawKeys as $rawKey) {
            $key = $keyMap[$rawKey];

            if (!array_key_exists($rawKey, $payloads)) {
                $this->cacheMisses++;
                $result[$key] = false;
                $found[$key] = false;

                continue;
            }

            $this->cacheHits++;
            $result[$key] = $this->codec->decode($payloads[$rawKey]);
            $found[$key] = true;
        }

        return $result;
    }

    public function incr(string $key, int $offset = 1): int|false
    {
        if ($offset < 0) {
            return $this->decr($key, abs($offset));
        }

        $value = $this->memcached->increment($this->rawKey($key), $offset);

        if ($value !== false) {
            return (int) $value;
        }

        return $this->mutateDecodedInteger($key, $offset, false);
    }

    public function decr(string $key, int $offset = 1): int|false
    {
        if ($offset < 0) {
            return $this->incr($key, abs($offset));
        }

        $value = $this->memcached->decrement($this->rawKey($key), $offset);

        if ($value !== false) {
            return (int) $value;
        }

        return $this->mutateDecodedInteger($key, $offset, true);
    }

    public function delete(string $key): bool
    {
        return (bool) $this->memcached->delete($this->rawKey($key));
    }

    public function deleteMultiple(array $keys): array
    {
        $rawKeys = [];
        $keyMap = [];

        foreach ($keys as $key) {
            $rawKey = $this->rawKey($key);
            $rawKeys[] = $rawKey;
            $keyMap[$rawKey] = $key;
        }

        $deleted = $this->memcached->deleteMulti($rawKeys);
        $result = [];

        foreach ($rawKeys as $rawKey) {
            $key = $keyMap[$rawKey];
            $result[$key] = is_array($deleted) ? (bool) ($deleted[$rawKey] ?? false) : (bool) $deleted;
        }

        return $result;
    }

    public function clear(string $prefix = ''): bool
    {
        return $this->bumpVersion($prefix);
    }

    public function purge(): bool
    {
        return true;
    }

    public function commit(): bool
    {
        return true;
    }

    public function cacheHits(): int
    {
        return $this->cacheHits;
    }

    public function cacheMisses(): int
    {
        return $this->cacheMisses;
    }

    private function rawKey(string $key): string
    {
        return sprintf(
            '%s:v%s:g%s:%s',
            $this->prefix,
            $this->version(''),
            $this->version($this->groupPrefixFromKey($key)),
            $key,
        );
    }

    private function groupPrefixFromKey(string $key): string
    {
        return strlen($key) > 64 ? substr($key, 0, -64) : '';
    }

    private function version(string $scope): int
    {
        $key = $this->versionKey($scope);
        $version = $this->memcached->get($key);

        if (is_numeric($version) && (int) $version > 0) {
            return (int) $version;
        }

        $this->memcached->add($key, '1', 0);

        return 1;
    }

    private function bumpVersion(string $scope): bool
    {
        $key = $this->versionKey($scope);
        $version = $this->memcached->increment($key, 1);

        if ($version !== false) {
            return true;
        }

        if ($this->memcached->add($key, '2', 0)) {
            return true;
        }

        $version = $this->memcached->get($key);

        return is_numeric($version) && (int) $version > 1;
    }

    private function versionKey(string $scope): string
    {
        return sprintf('%s:version:%s', $this->prefix, hash('xxh128', $scope));
    }

    private function lastResultCode(): int
    {
        return method_exists($this->memcached, 'getResultCode') ? (int) $this->memcached->getResultCode() : 0;
    }

    private function notFoundCode(): int
    {
        return defined('Memcached::RES_NOTFOUND') ? \Memcached::RES_NOTFOUND : 16;
    }

    private function mutateDecodedInteger(string $key, int $offset, bool $decrement): int|false
    {
        $found = false;
        $data = $this->get($key, $found);

        if (!$found) {
            return false;
        }

        $value = is_numeric($data) ? (int) $data : 0;
        $nextValue = $decrement ? max(0, $value - $offset) : max(0, $value + $offset);

        return $this->set($key, $nextValue) ? $nextValue : false;
    }
}
