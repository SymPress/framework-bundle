<?php

declare(strict_types=1);

namespace SymPress\Framework\ObjectCache;

final class ObjectCacheProxy
{
    public int $cache_hits = 0;
    public int $cache_misses = 0;

    /**
     * Query Monitor and older integrations inspect these public properties.
     *
     * @var array<string, mixed>
     */
    public array $cache = [];

    /**
     * @var array<string, true>
     */
    protected array $global_groups = [];

    /**
     * @var array<string, true>
     */
    private array $non_persistent_groups = [];

    public function __construct(
        private readonly ObjectCacheBackendInterface $runtime,
        private readonly ObjectCacheBackendInterface $persistent,
        private readonly CacheKeyGenerator $keyGenerator,
        private readonly bool $useLocalCache = true,
    ) {
    }

    public function __get(string $name): mixed
    {
        return $this->{$name};
    }

    public function __set(string $name, mixed $value): void
    {
        $this->{$name} = $value;
    }

    public function __isset(string $name): bool
    {
        return isset($this->{$name});
    }

    public function __unset(string $name): void
    {
        unset($this->{$name});
    }

    public function add(int|string $key, mixed $data, string $group = 'default', int $expire = 0): bool
    {
        if ($this->cacheAdditionSuspended()) {
            return false;
        }

        $cacheKey = $this->keyGenerator->create((string) $key, $group);
        $result = $this->pool($group)->add($cacheKey, $data, $expire);

        if ($result) {
            $this->remember($cacheKey, $data, $group);
        }

        return $result;
    }

    /**
     * @param array<int|string, mixed> $data
     * @return array<int|string, bool>
     */
    public function add_multiple(array $data, string $group = 'default', int $expire = 0): array
    {
        if ($this->cacheAdditionSuspended()) {
            return array_fill_keys(array_keys($data), false);
        }

        $cacheData = [];
        $keyMap = [];

        foreach ($data as $key => $value) {
            $cacheKey = $this->keyGenerator->create((string) $key, $group);
            $cacheData[$cacheKey] = $value;
            $keyMap[$cacheKey] = $key;
        }

        $result = [];

        foreach ($this->pool($group)->addMultiple($cacheData, $expire) as $cacheKey => $added) {
            $key = $keyMap[$cacheKey];
            $result[$key] = $added;

            if ($added) {
                $this->remember($cacheKey, $cacheData[$cacheKey], $group);
            }
        }

        return $result;
    }

    /**
     * @param string|array<int, string> $groups
     */
    public function add_global_groups(string|array $groups): bool
    {
        $groups = (array) $groups;

        foreach ($groups as $group) {
            $this->global_groups[(string) $group] = true;
        }

        $this->keyGenerator->addGlobalGroups($groups);

        return true;
    }

    /**
     * @param string|array<int, string> $groups
     * @return array<string, true>
     */
    public function add_non_persistent_groups(string|array $groups): array
    {
        foreach ((array) $groups as $group) {
            $this->non_persistent_groups[(string) $group] = true;
        }

        return $this->non_persistent_groups;
    }

    public function decr(int|string $key, int $offset = 1, string $group = 'default'): int|false
    {
        $cacheKey = $this->keyGenerator->create((string) $key, $group);
        $value = $this->pool($group)->decr($cacheKey, $offset);

        if ($value !== false) {
            $this->remember($cacheKey, $value, $group);
        }

        $this->syncStats();

        return $value;
    }

    public function delete(int|string $key, string $group = 'default'): bool
    {
        $cacheKey = $this->keyGenerator->create((string) $key, $group);
        unset($this->cache[$cacheKey]);

        return $this->pool($group)->delete($cacheKey);
    }

    /**
     * @param array<int, int|string> $keys
     * @return array<int|string, bool>
     */
    public function delete_multiple(array $keys, string $group = 'default'): array
    {
        $cacheKeys = [];
        $keyMap = [];

        foreach (array_unique($keys) as $key) {
            $cacheKey = $this->keyGenerator->create((string) $key, $group);
            $cacheKeys[] = $cacheKey;
            $keyMap[$cacheKey] = $key;
            unset($this->cache[$cacheKey]);
        }

        $deleted = $this->pool($group)->deleteMultiple($cacheKeys);
        $result = [];

        foreach ($deleted as $cacheKey => $success) {
            $result[$keyMap[$cacheKey]] = $success;
        }

        return $result;
    }

    public function flush(): bool
    {
        $this->cache = [];

        return $this->runtime->clear() && $this->persistent->clear();
    }

    public function flush_group(string $group): bool
    {
        $prefix = $this->keyGenerator->groupPrefix($group);

        foreach (array_keys($this->cache) as $key) {
            if (str_starts_with((string) $key, $prefix)) {
                unset($this->cache[$key]);
            }
        }

        return $this->pool($group)->clear($prefix);
    }

    public function flush_runtime(): bool
    {
        $this->cache = [];

        return $this->runtime->clear();
    }

    public function get(int|string $key, string $group = 'default', bool $force = false, ?bool &$found = null): mixed
    {
        $cacheKey = $this->keyGenerator->create((string) $key, $group);

        if (!$force && $this->useLocalCache && array_key_exists($cacheKey, $this->cache)) {
            $this->cache_hits++;
            $found = true;

            return $this->cache[$cacheKey];
        }

        $result = $this->pool($group)->get($cacheKey, $found);

        if ($found === true) {
            $this->remember($cacheKey, $result, $group);
        } else {
            unset($this->cache[$cacheKey]);
        }

        $this->syncStats();

        return $result;
    }

    /**
     * @param array<int, int|string> $keys
     * @return array<int|string, mixed>
     */
    public function get_multiple(array $keys, string $group = 'default', bool $force = false): array
    {
        $result = [];
        $cacheKeys = [];
        $keyMap = [];

        foreach (array_unique($keys) as $key) {
            $cacheKey = $this->keyGenerator->create((string) $key, $group);
            $result[$key] = false;

            if (!$force && $this->useLocalCache && array_key_exists($cacheKey, $this->cache)) {
                $this->cache_hits++;
                $result[$key] = $this->cache[$cacheKey];

                continue;
            }

            $cacheKeys[] = $cacheKey;
            $keyMap[$cacheKey] = $key;
        }

        if ($cacheKeys === []) {
            return $result;
        }

        $found = [];
        $values = $this->pool($group)->getMultiple($cacheKeys, $found);

        foreach ($cacheKeys as $cacheKey) {
            $key = $keyMap[$cacheKey];
            $value = $values[$cacheKey] ?? false;
            $result[$key] = $value;

            if (($found[$cacheKey] ?? false) === true) {
                $this->remember($cacheKey, $value, $group);

                continue;
            }

            unset($this->cache[$cacheKey]);
        }

        $this->syncStats();

        return $result;
    }

    public function incr(int|string $key, int $offset = 1, string $group = 'default'): int|false
    {
        $cacheKey = $this->keyGenerator->create((string) $key, $group);
        $value = $this->pool($group)->incr($cacheKey, $offset);

        if ($value !== false) {
            $this->remember($cacheKey, $value, $group);
        }

        $this->syncStats();

        return $value;
    }

    public function replace(int|string $key, mixed $data, string $group = 'default', int $expire = 0): bool
    {
        $cacheKey = $this->keyGenerator->create((string) $key, $group);
        $result = $this->pool($group)->replace($cacheKey, $data, $expire);

        if ($result) {
            $this->remember($cacheKey, $data, $group);
        }

        return $result;
    }

    public function reset(): bool
    {
        $this->cache = [];

        if (function_exists('get_current_blog_id')) {
            $this->switch_to_blog((int) get_current_blog_id());
        }

        return true;
    }

    public function set(int|string $key, mixed $data, string $group = 'default', int $expire = 0): bool
    {
        $cacheKey = $this->keyGenerator->create((string) $key, $group);
        $result = $this->pool($group)->set($cacheKey, $data, $expire);

        if ($result) {
            $this->remember($cacheKey, $data, $group);
        }

        return $result;
    }

    /**
     * @param array<int|string, mixed> $data
     * @return array<int|string, bool>
     */
    public function set_multiple(array $data, string $group = 'default', int $expire = 0): array
    {
        $cacheData = [];
        $keyMap = [];

        foreach ($data as $key => $value) {
            $cacheKey = $this->keyGenerator->create((string) $key, $group);
            $cacheData[$cacheKey] = $value;
            $keyMap[$cacheKey] = $key;
        }

        $result = [];

        foreach ($this->pool($group)->setMultiple($cacheData, $expire) as $cacheKey => $saved) {
            $key = $keyMap[$cacheKey];
            $result[$key] = $saved;

            if ($saved) {
                $this->remember($cacheKey, $cacheData[$cacheKey], $group);
            }
        }

        return $result;
    }

    public function stats(): void
    {
        printf(
            '<p><strong>Cache Hits:</strong> %d<br /><strong>Cache Misses:</strong> %d<br /></p>',
            $this->cache_hits,
            $this->cache_misses,
        );
    }

    public function switch_to_blog(int|string $blogId): bool
    {
        $this->cache = [];

        return $this->keyGenerator->switchToBlog((int) $blogId);
    }

    public function purge(): bool
    {
        return $this->runtime->purge() && $this->persistent->purge();
    }

    public function close(): bool
    {
        return $this->runtime->commit() && $this->persistent->commit();
    }

    private function pool(string $group): ObjectCacheBackendInterface
    {
        return isset($this->non_persistent_groups[$group]) ? $this->runtime : $this->persistent;
    }

    private function remember(string $cacheKey, mixed $data, string $group): void
    {
        if (!$this->useLocalCache) {
            return;
        }

        $this->cache[$cacheKey] = $data;

        if (isset($this->non_persistent_groups[$group])) {
            $this->runtime->set($cacheKey, $data);
        }
    }

    private function syncStats(): void
    {
        $this->cache_hits = max(
            $this->cache_hits,
            $this->runtime->cacheHits() + $this->persistent->cacheHits(),
        );
        $this->cache_misses = max(
            $this->cache_misses,
            $this->runtime->cacheMisses() + $this->persistent->cacheMisses(),
        );
    }

    private function cacheAdditionSuspended(): bool
    {
        return function_exists('wp_suspend_cache_addition') && (bool) wp_suspend_cache_addition();
    }
}
