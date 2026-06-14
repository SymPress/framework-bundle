<?php

declare(strict_types=1);

namespace SymPress\Framework\Cache;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\AdapterInterface;

final class CachePoolClearer
{
    /**
     * @param iterable<string, CacheItemPoolInterface> $pools
     */
    public function __construct(
        private iterable $pools,
    ) {
    }

    /**
     * @param array<int, string>|null $poolNames
     */
    public function clear(?array $poolNames = null): bool
    {
        $ok = true;
        $selected = $poolNames === null ? null : array_fill_keys($poolNames, true);

        foreach ($this->pools as $name => $pool) {
            if ($selected !== null && !isset($selected[(string) $name])) {
                continue;
            }

            $ok = $this->clearPool($pool) && $ok;
        }

        return $ok;
    }

    private function clearPool(CacheItemPoolInterface $pool): bool
    {
        if ($pool instanceof AdapterInterface) {
            return $pool->clear();
        }

        return $pool->clear();
    }
}
