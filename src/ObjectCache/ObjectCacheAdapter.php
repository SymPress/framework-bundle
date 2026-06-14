<?php

declare(strict_types=1);

namespace SymPress\Framework\ObjectCache;

use Psr\Cache\CacheItemInterface;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Cache\PruneableInterface;

final class ObjectCacheAdapter implements ObjectCacheBackendInterface
{
    public int $cacheHits = 0;
    public int $cacheMisses = 0;

    public function __construct(
        private readonly AdapterInterface $pool,
    ) {
    }

    public function add(string $key, mixed $data, int $expire = 0): bool
    {
        try {
            $item = $this->pool->getItem($key);
        } catch (\InvalidArgumentException) {
            return false;
        }

        if ($item->isHit()) {
            return false;
        }

        $item->set($data);

        if ($expire > 0) {
            $item->expiresAfter($expire);
        }

        return $this->pool->save($item);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, bool>
     */
    public function addMultiple(array $data, int $expire = 0): array
    {
        $result = [];
        $items = [];

        try {
            $items = iterator_to_array($this->pool->getItems(array_map('strval', array_keys($data))));
        } catch (\InvalidArgumentException) {
            foreach ($data as $key => $_value) {
                $result[$key] = false;
            }

            return $result;
        }

        foreach ($data as $key => $value) {
            $cacheKey = (string) $key;
            $item = $items[$cacheKey] ?? null;

            if (!$item instanceof CacheItemInterface || $item->isHit()) {
                $result[$key] = false;

                continue;
            }

            $item->set($value);

            if ($expire > 0) {
                $item->expiresAfter($expire);
            }

            $result[$key] = $this->pool->saveDeferred($item);
        }

        if (!$this->pool->commit()) {
            foreach ($result as $key => $saved) {
                if ($saved) {
                    $result[$key] = false;
                }
            }
        }

        return $result;
    }

    public function set(string $key, mixed $data, int $expire = 0): bool
    {
        try {
            $item = $this->pool->getItem($key);
        } catch (\InvalidArgumentException) {
            return false;
        }

        $item->set($data);

        if ($expire > 0) {
            $item->expiresAfter($expire);
        }

        return $this->pool->save($item);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, bool>
     */
    public function setMultiple(array $data, int $expire = 0): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            try {
                $item = $this->pool->getItem((string) $key);
            } catch (\InvalidArgumentException) {
                $result[$key] = false;

                continue;
            }

            $item->set($value);

            if ($expire > 0) {
                $item->expiresAfter($expire);
            }

            $result[$key] = $this->pool->saveDeferred($item);
        }

        if (!$this->pool->commit()) {
            foreach ($result as $key => $saved) {
                if ($saved) {
                    $result[$key] = false;
                }
            }
        }

        return $result;
    }

    public function replace(string $key, mixed $data, int $expire = 0): bool
    {
        try {
            $item = $this->pool->getItem($key);
        } catch (\InvalidArgumentException) {
            return false;
        }

        if (!$item->isHit()) {
            return false;
        }

        $item->set($data);

        if ($expire > 0) {
            $item->expiresAfter($expire);
        }

        return $this->pool->save($item);
    }

    /**
     * @param-out bool $found
     */
    public function get(string $key, ?bool &$found = null): mixed
    {
        try {
            $item = $this->pool->getItem($key);
        } catch (\InvalidArgumentException) {
            $found = false;

            return false;
        }

        return $this->valueFromItem($item, $found);
    }

    /**
     * @param array<int, string> $keys
     * @param-out array<string, bool> $found
     * @return array<string, mixed>
     */
    public function getMultiple(array $keys, ?array &$found = null): array
    {
        $result = [];
        $found = [];

        try {
            $items = $this->pool->getItems($keys);
        } catch (\InvalidArgumentException) {
            foreach ($keys as $key) {
                $result[$key] = false;
                $found[$key] = false;
            }

            return $result;
        }

        foreach ($items as $item) {
            $itemFound = null;
            $result[$item->getKey()] = $this->valueFromItem($item, $itemFound);
            $found[$item->getKey()] = $itemFound === true;
        }

        return $result;
    }

    public function incr(string $key, int $offset = 1): int|false
    {
        if ($offset < 0) {
            return $this->decr($key, abs($offset));
        }

        $found = false;
        $data = $this->get($key, $found);

        if (!$found) {
            return false;
        }

        $value = (is_numeric($data) ? (int) $data : 0) + $offset;

        return $this->set($key, max(0, $value)) ? max(0, $value) : false;
    }

    public function decr(string $key, int $offset = 1): int|false
    {
        if ($offset < 0) {
            return $this->incr($key, abs($offset));
        }

        $found = false;
        $data = $this->get($key, $found);

        if (!$found) {
            return false;
        }

        $value = max(0, (is_numeric($data) ? (int) $data : 0) - $offset);

        return $this->set($key, $value) ? $value : false;
    }

    public function delete(string $key): bool
    {
        if (!$this->pool->hasItem($key)) {
            return false;
        }

        return $this->pool->deleteItem($key);
    }

    /**
     * @param array<int, string> $keys
     * @return array<string, bool>
     */
    public function deleteMultiple(array $keys): array
    {
        $result = [];
        $existing = [];

        foreach ($keys as $key) {
            $exists = $this->pool->hasItem($key);
            $result[$key] = false;

            if ($exists) {
                $existing[] = $key;
            }
        }

        if ($existing === []) {
            return $result;
        }

        try {
            $success = $this->pool->deleteItems($existing);
        } catch (\InvalidArgumentException) {
            $success = false;
        }

        foreach ($existing as $key) {
            $result[$key] = $success;
        }

        return $result;
    }

    public function clear(string $prefix = ''): bool
    {
        return $this->pool->clear($prefix);
    }

    public function purge(): bool
    {
        if (!$this->pool instanceof PruneableInterface) {
            return true;
        }

        return $this->pool->prune();
    }

    public function commit(): bool
    {
        return $this->pool->commit();
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
     * @param-out bool $found
     */
    private function valueFromItem(CacheItemInterface $item, ?bool &$found): mixed
    {
        if (!$item->isHit()) {
            $this->cacheMisses++;
            $found = false;

            return false;
        }

        $this->cacheHits++;
        $found = true;

        return $item->get();
    }
}
