<?php

declare(strict_types=1);

namespace SymPress\Framework\Cache;

use Symfony\Component\Cache\PruneableInterface;

final class CachePoolPruner
{
    /**
     * @param iterable<string, object> $pools
     */
    public function __construct(
        private iterable $pools,
    ) {
    }

    /**
     * @param array<int, string>|null $poolNames
     */
    public function prune(?array $poolNames = null): bool
    {
        $ok = true;
        $selected = $poolNames === null ? null : array_fill_keys($poolNames, true);

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
