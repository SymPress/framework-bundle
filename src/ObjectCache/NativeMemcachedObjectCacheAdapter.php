<?php

declare(strict_types=1);

namespace SymPress\Framework\ObjectCache;

final class NativeMemcachedObjectCacheAdapter implements ObjectCacheBackendInterface
{
    private const int MAX_RELATIVE_TTL = 2592000;

    private ObjectCacheValueCodec $codec;
    private int $cacheHits = 0;
    private int $cacheMisses = 0;

    /**
     * Namespace versions read during this request, so each key costs one round trip.
     *
     * @var array<string, string>
     */
    private array $versions = [];

    public function __construct(
        private readonly object $memcached,
        private readonly string $prefix,
        ?ObjectCacheValueCodec $codec = null,
    ) {
        $this->codec = $codec ?? new ObjectCacheValueCodec();
    }

    public function add(string $key, mixed $data, int $expire = 0): bool
    {
        return (bool) $this->memcached->add($this->rawKey($key), $this->codec->encode($data), $this->expiration($expire));
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
        return (bool) $this->memcached->set($this->rawKey($key), $this->codec->encode($data), $this->expiration($expire));
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
        return (bool) $this->memcached->replace($this->rawKey($key), $this->codec->encode($data), $this->expiration($expire));
    }

    /**
     * @param-out bool $found
     */
    public function get(string $key, ?bool &$found = null): mixed
    {
        $rawKey = $this->rawKey($key);
        $payload = $this->memcached->get($rawKey);

        if ($payload === false && $this->lastResultCode() === $this->notFoundCode()) {
            $this->cacheMisses++;
            $found = false;

            return false;
        }

        $value = $this->codec->decode($payload, $valid);

        if (!$valid) {
            // Written under another secret or tampered with: drop it so add() can refill the key.
            $this->memcached->delete($rawKey);
            $this->cacheMisses++;
            $found = false;

            return false;
        }

        $this->cacheHits++;
        $found = true;

        return $value;
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
        $invalid = [];

        foreach ($rawKeys as $rawKey) {
            $key = $keyMap[$rawKey];

            if (array_key_exists($rawKey, $payloads)) {
                $value = $this->codec->decode($payloads[$rawKey], $valid);

                if ($valid) {
                    $this->cacheHits++;
                    $result[$key] = $value;
                    $found[$key] = true;

                    continue;
                }

                $invalid[] = $rawKey;
            }

            $this->cacheMisses++;
            $result[$key] = false;
            $found[$key] = false;
        }

        if ($invalid !== []) {
            $this->memcached->deleteMulti($invalid);
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

    private function expiration(int $expire): int
    {
        // Memcached reads relative TTLs above 30 days as absolute Unix timestamps.
        return $expire > self::MAX_RELATIVE_TTL ? time() + $expire : $expire;
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

    /**
     * Versions are random tokens, never counters: a version key lost to eviction gets a fresh
     * token instead of restarting at a value that entries from before a flush still carry.
     */
    private function version(string $scope): string
    {
        if (isset($this->versions[$scope])) {
            return $this->versions[$scope];
        }

        $key = $this->versionKey($scope);
        $version = $this->memcached->get($key);

        if ($version === false && $this->lastResultCode() === $this->notFoundCode()) {
            $candidate = self::newVersion();
            $version = $this->memcached->add($key, $candidate, 0) ? $candidate : $this->memcached->get($key);
        }

        // Counters written by earlier releases may come back as integers.
        $version = is_int($version) || is_string($version) ? (string) $version : '';

        if ($version === '') {
            // Unknown version: a throwaway token keeps this call from hitting entries from before a flush.
            return self::newVersion();
        }

        return $this->versions[$scope] = $version;
    }

    private function bumpVersion(string $scope): bool
    {
        $version = self::newVersion();

        if (!$this->memcached->set($this->versionKey($scope), $version, 0)) {
            return false;
        }

        $this->versions[$scope] = $version;

        return true;
    }

    private static function newVersion(): string
    {
        return bin2hex(random_bytes(6));
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
