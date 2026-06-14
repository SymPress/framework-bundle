<?php

declare(strict_types=1);

use SymPress\Framework\ObjectCache\ObjectCacheDropIn;
use SymPress\Framework\ObjectCache\ObjectCacheProxy;

function sympress_object_cache(): ObjectCacheProxy
{
    return ObjectCacheDropIn::proxy();
}

if (!function_exists('wp_cache_add')) {
    function wp_cache_add($key, $data, $group = '', $expire = 0): bool
    {
        return sympress_object_cache()->add($key, $data, (string) $group, (int) $expire);
    }
}

if (!function_exists('wp_cache_add_multiple')) {
    function wp_cache_add_multiple(array $data, $group = '', $expire = 0): array
    {
        return sympress_object_cache()->add_multiple($data, (string) $group, (int) $expire);
    }
}

if (!function_exists('wp_cache_close')) {
    function wp_cache_close(): bool
    {
        return sympress_object_cache()->close();
    }
}

if (!function_exists('wp_cache_decr')) {
    function wp_cache_decr($key, $offset = 1, $group = '')
    {
        return sympress_object_cache()->decr($key, (int) $offset, (string) $group);
    }
}

if (!function_exists('wp_cache_delete')) {
    function wp_cache_delete($key, $group = ''): bool
    {
        return sympress_object_cache()->delete($key, (string) $group);
    }
}

if (!function_exists('wp_cache_delete_multiple')) {
    function wp_cache_delete_multiple(array $keys, $group = ''): array
    {
        return sympress_object_cache()->delete_multiple($keys, (string) $group);
    }
}

if (!function_exists('wp_cache_flush')) {
    function wp_cache_flush(): bool
    {
        return sympress_object_cache()->flush();
    }
}

if (!function_exists('wp_cache_flush_group')) {
    function wp_cache_flush_group($group): bool
    {
        return sympress_object_cache()->flush_group((string) $group);
    }
}

if (!function_exists('wp_cache_flush_runtime')) {
    function wp_cache_flush_runtime(): bool
    {
        return sympress_object_cache()->flush_runtime();
    }
}

if (!function_exists('wp_cache_get')) {
    function wp_cache_get($key, $group = '', $force = false, &$found = null)
    {
        return sympress_object_cache()->get($key, (string) $group, (bool) $force, $found);
    }
}

if (!function_exists('wp_cache_get_multiple')) {
    function wp_cache_get_multiple($keys, $group = '', $force = false): array
    {
        return sympress_object_cache()->get_multiple((array) $keys, (string) $group, (bool) $force);
    }
}

if (!function_exists('wp_cache_incr')) {
    function wp_cache_incr($key, $offset = 1, $group = '')
    {
        return sympress_object_cache()->incr($key, (int) $offset, (string) $group);
    }
}

if (!function_exists('wp_cache_init')) {
    function wp_cache_init(): void
    {
        ObjectCacheDropIn::init();
    }
}

if (!function_exists('wp_cache_replace')) {
    function wp_cache_replace($key, $data, $group = '', $expire = 0): bool
    {
        return sympress_object_cache()->replace($key, $data, (string) $group, (int) $expire);
    }
}

if (!function_exists('wp_cache_reset')) {
    function wp_cache_reset(): bool
    {
        return sympress_object_cache()->reset();
    }
}

if (!function_exists('wp_cache_set')) {
    function wp_cache_set($key, $data, $group = '', $expire = 0): bool
    {
        return sympress_object_cache()->set($key, $data, (string) $group, (int) $expire);
    }
}

if (!function_exists('wp_cache_set_multiple')) {
    function wp_cache_set_multiple(array $data, $group = '', $expire = 0): array
    {
        return sympress_object_cache()->set_multiple($data, (string) $group, (int) $expire);
    }
}

if (!function_exists('wp_cache_switch_to_blog')) {
    function wp_cache_switch_to_blog($blog_id): bool
    {
        return sympress_object_cache()->switch_to_blog($blog_id);
    }
}

if (!function_exists('wp_cache_add_global_groups')) {
    function wp_cache_add_global_groups($groups): bool
    {
        return sympress_object_cache()->add_global_groups($groups);
    }
}

if (!function_exists('wp_cache_add_non_persistent_groups')) {
    function wp_cache_add_non_persistent_groups($groups): array
    {
        return sympress_object_cache()->add_non_persistent_groups($groups);
    }
}

if (!function_exists('wp_cache_supports')) {
    function wp_cache_supports($feature): bool
    {
        return in_array(
            (string) $feature,
            [
                'add_multiple',
                'set_multiple',
                'get_multiple',
                'delete_multiple',
                'flush_runtime',
                'flush_group',
            ],
            true,
        );
    }
}
