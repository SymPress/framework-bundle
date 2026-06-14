<?php

declare(strict_types=1);

namespace SymPress\Framework\ObjectCache\Hook;

use SymPress\Framework\ObjectCache\CacheConfigFactory;
use SymPress\Framework\ObjectCache\ObjectCacheProxy;

final class ScheduledPurgeHook
{
    public const string HOOK = 'sympress.framework.cache_purge';

    public function schedule(): void
    {
        if (!function_exists('wp_next_scheduled') || !function_exists('wp_schedule_single_event')) {
            return;
        }

        if (wp_next_scheduled(self::HOOK)) {
            return;
        }

        $interval = (new CacheConfigFactory())->create()->purgeInterval;

        if ($interval <= 0) {
            return;
        }

        wp_schedule_single_event(time() + $interval, self::HOOK);
    }

    public function purge(): void
    {
        $cache = $GLOBALS['wp_object_cache'] ?? null;

        if ($cache instanceof ObjectCacheProxy) {
            $cache->purge();
        }
    }
}
