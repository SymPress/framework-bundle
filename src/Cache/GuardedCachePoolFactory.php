<?php

declare(strict_types=1);

namespace SymPress\Framework\Cache;

use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;

final class GuardedCachePoolFactory
{
    /** @param \Closure(): AdapterInterface $persistent */
    public static function create(?string $secret, \Closure $persistent): AdapterInterface
    {
        return $secret === null || strlen($secret) < 32 ? new TagAwareAdapter(new ArrayAdapter()) : $persistent();
    }
}
