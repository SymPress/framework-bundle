<?php

declare(strict_types=1);

namespace SymPress\Framework\ObjectCache;

final class ObjectCacheDropIn
{
    public static function init(): ObjectCacheProxy
    {
        $config = (new CacheConfigFactory())->create();
        $factory = new CacheAdapterFactory();

        $proxy = new ObjectCacheProxy(
            $factory->createRuntime(),
            $factory->createPersistent($config),
            self::keyGenerator(),
            $config->inMemory,
        );

        $GLOBALS['wp_object_cache'] = $proxy;

        return $proxy;
    }

    public static function proxy(): ObjectCacheProxy
    {
        $cache = $GLOBALS['wp_object_cache'] ?? null;

        if ($cache instanceof ObjectCacheProxy) {
            return $cache;
        }

        return self::init();
    }

    private static function keyGenerator(): CacheKeyGenerator
    {
        if (function_exists('is_multisite') && is_multisite() && function_exists('get_current_blog_id')) {
            return new CacheKeyGenerator((int) get_current_blog_id());
        }

        return new CacheKeyGenerator();
    }
}
