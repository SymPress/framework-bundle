<?php

declare(strict_types=1);

namespace SymPress\Framework\Cache;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\PruneableInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

final class CachePoolRegistry
{
    /**
     * @param iterable<string, CacheItemPoolInterface> $pools
     * @param iterable<string, TagAwareCacheInterface> $taggablePools
     */
    public function __construct(
        private iterable $pools,
        private iterable $taggablePools = [],
        private array $poolNames = [],
        private array $taggablePoolNames = [],
    ) {
    }

    /**
     * @return array<int, string>
     */
    public function names(): array
    {
        if ($this->poolNames !== []) {
            $names = $this->poolNames;
            sort($names);

            return $names;
        }

        $names = [];

        foreach ($this->pools as $name => $_pool) {
            $names[] = (string) $name;
        }

        sort($names);

        return $names;
    }

    /**
     * @return array<int, string>
     */
    public function taggableNames(): array
    {
        if ($this->taggablePoolNames !== []) {
            $names = $this->taggablePoolNames;
            sort($names);

            return $names;
        }

        $names = [];

        foreach ($this->taggablePools as $name => $_pool) {
            $names[] = (string) $name;
        }

        sort($names);

        return $names;
    }

    public function get(string $name): ?CacheItemPoolInterface
    {
        foreach ($this->pools as $poolName => $pool) {
            if ((string) $poolName === $name) {
                return $pool;
            }
        }

        return null;
    }

    public function has(string $name): bool
    {
        return $this->get($name) !== null;
    }

    public function getTaggable(string $name): ?TagAwareCacheInterface
    {
        foreach ($this->taggablePools as $poolName => $pool) {
            if ((string) $poolName === $name) {
                return $pool;
            }
        }

        return null;
    }

    public function hasTaggable(string $name): bool
    {
        return $this->getTaggable($name) !== null;
    }

    /**
     * @param array<int, string> $names
     * @return array<int, string>
     */
    public function missing(array $names): array
    {
        $available = array_fill_keys($this->names(), true);

        return array_values(array_filter(
            $names,
            static fn (string $name): bool => !isset($available[$name]),
        ));
    }

    /**
     * @param array<int, string> $names
     */
    public function clear(array $names = []): bool
    {
        $ok = true;
        $selected = $names === [] ? null : array_fill_keys($names, true);

        foreach ($this->pools as $name => $pool) {
            if ($selected !== null && !isset($selected[(string) $name])) {
                continue;
            }

            $ok = $pool->clear() && $ok;
        }

        return $ok;
    }

    /**
     * @param array<int, string> $names
     */
    public function prune(array $names = []): bool
    {
        $ok = true;
        $selected = $names === [] ? null : array_fill_keys($names, true);

        foreach ($this->pools as $name => $pool) {
            if ($selected !== null && !isset($selected[(string) $name])) {
                continue;
            }

            if (!$pool instanceof PruneableInterface) {
                continue;
            }

            $ok = $pool->prune() && $ok;
        }

        return $ok;
    }
}
