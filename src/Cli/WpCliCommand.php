<?php

declare(strict_types=1);

namespace SymPress\Framework\Cli;

use SymPress\Framework\ObjectCache\ObjectCacheProxy;

final class WpCliCommand
{
    /**
     * Flush the WordPress object cache for the current WP-CLI process.
     */
    public function flush(array $args = [], array $assocArgs = []): void
    {
        $this->flushDirect();
    }

    /**
     * Prune expired entries for pruneable cache backends.
     */
    public function purge(array $args = [], array $assocArgs = []): void
    {
        $cache = $GLOBALS['wp_object_cache'] ?? null;
        $result = $cache instanceof ObjectCacheProxy ? $cache->purge() : false;

        if ($result) {
            \WP_CLI::success('Object cache purged successfully.');

            return;
        }

        \WP_CLI::error('Object cache could not be purged.');
    }

    private function flushDirect(): void
    {
        if (function_exists('wp_cache_flush') && wp_cache_flush()) {
            \WP_CLI::success('Object cache flushed successfully.');

            return;
        }

        \WP_CLI::error('Object cache could not be flushed.');
    }

}
