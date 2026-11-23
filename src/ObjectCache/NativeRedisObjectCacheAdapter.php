<?php

declare(strict_types=1);

namespace SymPress\Framework\ObjectCache;

final class NativeRedisObjectCacheAdapter implements ObjectCacheBackendInterface
{
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
        private readonly object $redis,
        private readonly string $prefix,
        ?ObjectCacheValueCodec $codec = null,
    ) {
        $this->codec = $codec ?? new ObjectCacheValueCodec();
    }

    public function add(string $key, mixed $data, int $expire = 0): bool
    {
        return $this->setWithMode($key, $data, $expire, 'NX');
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
        $rawKey = $this->rawKey($key);
        $payload = $this->codec->encode($data);

        try {
            $result = $expire > 0
                ? $this->redis->setex($rawKey, $expire, $payload)
                : $this->redis->set($rawKey, $payload);
        } catch (\Throwable) {
            return false;
        }

        return $this->isOk($result);
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
        return $this->setWithMode($key, $data, $expire, 'XX');
    }

    /**
     * @param-out bool $found
     */
    public function get(string $key, ?bool &$found = null): mixed
    {
        $rawKey = $this->rawKey($key);

        try {
            $payload = $this->redis->get($rawKey);
        } catch (\Throwable) {
            $payload = false;
        }

        if ($payload === false || $payload === null) {
            $this->cacheMisses++;
            $found = false;

            return false;
        }

        $value = $this->codec->decode($payload, $valid);

        if (!$valid) {
            // Written under another secret or tampered with: drop it so add() can refill the key.
            $this->deleteRaw([$rawKey]);
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
        $rawKeys = array_map($this->rawKey(...), $keys);
        $result = [];
        $found = [];

        try {
            $payloads = $this->redis->mget($rawKeys);
        } catch (\Throwable) {
            $payloads = array_fill(0, count($rawKeys), false);
        }

        $invalid = [];

        foreach (array_values($keys) as $index => $key) {
            $payload = is_array($payloads) ? ($payloads[$index] ?? false) : false;

            if ($payload !== false) {
                $value = $this->codec->decode($payload, $valid);

                if ($valid) {
                    $this->cacheHits++;
                    $result[$key] = $value;
                    $found[$key] = true;

                    continue;
                }

                $invalid[] = $rawKeys[$index];
            }

            $this->cacheMisses++;
            $result[$key] = false;
            $found[$key] = false;
        }

        $this->deleteRaw($invalid);

        return $result;
    }

    public function incr(string $key, int $offset = 1): int|false
    {
        if ($offset < 0) {
            return $this->decr($key, abs($offset));
        }

        return $this->mutateInteger($key, $offset, false);
    }

    public function decr(string $key, int $offset = 1): int|false
    {
        if ($offset < 0) {
            return $this->incr($key, abs($offset));
        }

        return $this->mutateInteger($key, $offset, true);
    }

    public function delete(string $key): bool
    {
        try {
            $result = $this->redis->del($this->rawKey($key));
        } catch (\Throwable) {
            return false;
        }

        return (int) $result > 0;
    }

    public function deleteMultiple(array $keys): array
    {
        $result = [];

        foreach ($keys as $key) {
            $result[$key] = $this->delete($key);
        }

        return $result;
    }

    public function clear(string $prefix = ''): bool
    {
        try {
            return $this->bumpVersion($prefix);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Flushes only switch to a new namespace version; this removes entries of superseded versions.
     */
    public function purge(): bool
    {
        // shortcut: phpredis/Relay SCAN signature only; Predis and cluster clients keep relying on eviction.
        if (!method_exists($this->redis, 'scan') || $this->redis instanceof \RedisArray || str_contains($this->redis::class, 'Cluster') || str_starts_with($this->redis::class, 'Predis\\')) {
            return true;
        }

        // Compare against the stored versions, not the ones this request cached earlier.
        $this->versions = [];
        $iterator = null;

        try {
            do {
                $rawKeys = $this->scan($iterator, addcslashes($this->prefix, '\\*?[]') . ':v*');
                $stale = is_array($rawKeys) ? array_values(array_filter($rawKeys, $this->isStale(...))) : [];

                if ($stale !== []) {
                    $this->redis->unlink($stale);
                }
            } while ($iterator > 0);
        } catch (\Throwable) {
            return false;
        }

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

    /**
     * @param list<string> $rawKeys
     */
    private function deleteRaw(array $rawKeys): void
    {
        if ($rawKeys === []) {
            return;
        }

        try {
            $this->redis->del($rawKeys);
        } catch (\Throwable) {
            // A failed cleanup only leaves the undecodable entry for the next read.
        }
    }

    private function setWithMode(string $key, mixed $data, int $expire, string $mode): bool
    {
        $rawKey = $this->rawKey($key);
        $payload = $this->codec->encode($data);
        $options = [strtolower($mode)];

        if ($expire > 0) {
            $options['ex'] = $expire;
        }

        try {
            $result = $this->redis->set($rawKey, $payload, $options);
        } catch (\Throwable) {
            try {
                $result = $expire > 0
                    ? $this->redis->set($rawKey, $payload, 'EX', $expire, $mode)
                    : $this->redis->set($rawKey, $payload, $mode);
            } catch (\Throwable) {
                return false;
            }
        }

        return $this->isOk($result);
    }

    private function mutateInteger(string $key, int $offset, bool $decrement): int|false
    {
        $script = <<<'LUA'
local value = redis.call('get', KEYS[1])
if not value then
    return {0, 0}
end
if string.match(value, '^%d+$') == nil then
    return {2, 0}
end

local next_value = tonumber(value)
if ARGV[1] == 'decr' then
    next_value = next_value - tonumber(ARGV[2])
    if next_value < 0 then
        next_value = 0
    end
else
    next_value = next_value + tonumber(ARGV[2])
end

local ttl = redis.call('pttl', KEYS[1])
redis.call('set', KEYS[1], tostring(next_value))
if ttl > 0 then
    redis.call('pexpire', KEYS[1], ttl)
end

return {1, next_value}
LUA;

        try {
            $result = $this->evalScript($script, [$this->rawKey($key)], [$decrement ? 'decr' : 'incr', (string) $offset]);
        } catch (\Throwable) {
            return false;
        }

        if (!is_array($result)) {
            return false;
        }

        $status = (int) ($result[0] ?? 0);

        if ($status === 1) {
            return (int) ($result[1] ?? 0);
        }

        if ($status === 2) {
            return $this->mutateDecodedInteger($key, $offset, $decrement);
        }

        return false;
    }

    /**
     * @param array<int, string> $keys
     * @param array<int, string> $arguments
     */
    private function evalScript(string $script, array $keys, array $arguments): mixed
    {
        try {
            return $this->redis->eval($script, array_merge($keys, $arguments), count($keys));
        } catch (\ArgumentCountError|\TypeError) {
            return $this->redis->eval($script, count($keys), ...$keys, ...$arguments);
        }
    }

    private function isOk(mixed $result): bool
    {
        return $result === true || $result === 'OK' || (is_object($result) && (string) $result === 'OK');
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

    /**
     * @param-out int|null $iterator
     */
    private function scan(?int &$iterator, string $pattern): mixed
    {
        return $this->redis->scan($iterator, $pattern, 1000);
    }

    private function isStale(string $rawKey): bool
    {
        // <prefix>:v<global>:g<group>:<key>; version keys (<prefix>:version:<hash>) do not split into three parts.
        $parts = explode(':', substr($rawKey, strlen($this->prefix) + 2), 3);

        if (count($parts) !== 3 || !str_starts_with($parts[1], 'g')) {
            return false;
        }

        return $parts[0] !== $this->version('')
            || substr($parts[1], 1) !== $this->version($this->groupPrefixFromKey($parts[2]));
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

        try {
            $version = $this->redis->get($key);

            if (!is_string($version) || $version === '') {
                $candidate = self::newVersion();
                $version = $this->setVersionIfMissing($key, $candidate) ? $candidate : $this->redis->get($key);
            }
        } catch (\Throwable) {
            $version = null;
        }

        if (!is_string($version) || $version === '') {
            // Unknown version: a throwaway token keeps this call from hitting entries from before a flush.
            return self::newVersion();
        }

        return $this->versions[$scope] = $version;
    }

    private function bumpVersion(string $scope): bool
    {
        $version = self::newVersion();

        if (!$this->isOk($this->redis->set($this->versionKey($scope), $version))) {
            return false;
        }

        $this->versions[$scope] = $version;

        return true;
    }

    private function setVersionIfMissing(string $key, string $version): bool
    {
        try {
            return $this->isOk($this->redis->set($key, $version, ['nx']));
        } catch (\Throwable) {
            return $this->isOk($this->redis->set($key, $version, 'NX'));
        }
    }

    private static function newVersion(): string
    {
        return bin2hex(random_bytes(6));
    }

    private function versionKey(string $scope): string
    {
        return sprintf('%s:version:%s', $this->prefix, hash('xxh128', $scope));
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
