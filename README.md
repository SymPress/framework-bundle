# SymPress Framework Bundle

`sympress/framework-bundle` is a SymPress bundle that brings Symfony-style framework services into WordPress projects.
It now uses Symfony's real `symfony/framework-bundle` as the compatibility layer and keeps the SymPress
WordPress object-cache integration on top.

The core surfaces are:

- Symfony FrameworkBundle web/kernel services such as request stack, HTTP kernel, controllers, error rendering,
  routing infrastructure and console integration.
- Symfony Cache 8.2 based PSR-6, PSR-16 and Symfony Contracts cache services.
- Framework-Bundle-style cache pool services such as `cache.app`, `cache.system`, `cache.validator`, `cache.serializer`, adapter prototypes, clearers, pruners, tag-aware pools, Redis tag-aware pools, named custom pools and `cache:pool:*` console commands.
- WordPress `object-cache.php` drop-in support with a Symfony-oriented operational surface: APCu, Redis, Memcached, filesystem, SQLite/PDO, request-memory caching, multisite global groups, non-persistent groups, group flushing, runtime flushing, admin-bar flush, WP-CLI flush/purge and scheduled purge.

Optional FrameworkBundle integrations such as Form, Validator, Serializer, Messenger, Mailer, Notifier, HttpClient,
Workflow, Lock, RateLimiter, UID, WebLink and Webhook are enabled through Symfony's upstream configuration whenever
the matching Symfony component is installed.

## Install

Require the package in a SymPress project:

```sh
composer require sympress/framework-bundle
```

Production projects must provide a strong `APP_SECRET`. The bundle passes `APP_SECRET` directly to Symfony's
FrameworkBundle and does not fall back to predictable project paths.

The bundle is discovered through Composer metadata:

```json
{
  "extra": {
    "kernel": {
      "bundle": "SymPress\\Framework\\SymPressFrameworkBundle",
            "entry": "framework-bundle"
        }
    }
}
```

## Local Development

Run the package checks before opening a pull request:

```sh
composer qa
```

The QA script runs PHPCS, PHPStan and PHPUnit. The GitHub workflow uses the
shared SymPress workflow set and the organization `.github` repository provides
issue and community templates.

## Cache Pool Configuration

The bundle provides defaults that work without project config. Projects can override the `framework.cache` parameter in a SymPress config file:

```php
<?php

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $container->parameters()->set('framework.cache', [
        'app' => 'cache.adapter.redis',
        'default_redis_provider' => 'redis://redis:6379',
        'pools' => [
            'cache.marketing' => [
                'adapter' => 'cache.adapter.filesystem',
                'default_lifetime' => 600,
                'tags' => true,
                'public' => true,
            ],
            'cache.remote' => [
                'adapters' => [
                    ['name' => 'cache.adapter.redis', 'provider' => 'redis://redis:6379'],
                    'cache.adapter.array',
                ],
            ],
        ],
    ]);
};
```

Symfony-style extension configuration is also supported:

```yaml
framework:
  secret: '%env(APP_SECRET)%'
  router:
    resource: '%kernel.project_dir%/config/routes.yaml'
  cache:
    app: cache.adapter.redis
    default_redis_provider: 'redis://redis:6379'
```

Pool configuration accepts Symfony-style `adapter` or `adapters` keys, provider-specific adapter entries, `tags`, `default_lifetime`, `marshaller`, `clearer` and `early_expiration_message_bus` tag attributes. `cache.adapter.redis_tag_aware` and `cache.adapter.valkey_tag_aware` are treated as native tag-aware pools instead of being wrapped in a generic `TagAwareAdapter`.

## Object Cache Configuration

The WordPress drop-in can be configured with constants or environment variables:

```php
define('SYMPRESS_CACHE_DRIVER', 'redis');
define('SYMPRESS_CACHE_DSN', 'redis://redis:6379');
define('SYMPRESS_CACHE_IN_MEMORY', true);
define('SYMPRESS_CACHE_PURGE_INTERVAL', 43200);
define('SYMPRESS_CACHE_SECRET', getenv('APP_SECRET'));
```

Supported drivers are `array`, `filesystem`, `apcu`, `redis`, `memcached`, `pdo`, `sqlite` and `null`. `SYMPRESS_CACHE_DRIVER_ARGS` accepts JSON or base64-encoded JSON for driver-specific settings.
`SYMPRESS_CACHE_BYPASS` can be set as a constant or environment variable to disable the drop-in for emergency operations.

## Object Cache Drop-In

The canonical drop-in source is `vendor/sympress/framework-bundle/dropin/object-cache.php`.
Projects managed by `wecodemore/wpstarter` should publish that file into
`WP_CONTENT_DIR/object-cache.php` through the WP Starter `dropins` step:

```json
{
    "dropins": {
        "object-cache.php": "vendor/sympress/framework-bundle/dropin/object-cache.php"
    }
}
```

Run only the drop-in publish step when the source changes:

```bash
composer wpstarter dropins --no-interaction
```

The bundle also installs the same portable delegator into `WP_CONTENT_DIR` as a fallback for projects without WP Starter.
Managed SymPress drop-ins are only rewritten when their contents change and third-party drop-ins without the `sympress-framework-object-cache` marker are left untouched by the runtime installer.
WP Starter remains the preferred owner in WP Starter projects because it publishes the drop-in during Composer/project setup instead of waiting for the first WordPress request.

The delegator resolves the Composer autoloader from `SYMPRESS_PROJECT_DIR`, `APP_PROJECT_DIR`, `WP_CONTENT_DIR`, `ABSPATH`, or nearby parent directories; `SYMPRESS_COMPOSER_AUTOLOAD` and `SYMPRESS_OBJECT_CACHE_FUNCTIONS` can override those paths for custom layouts.
This makes the same file work when copied by WP Starter, symlinked from `content-dev`, or installed by the runtime fallback.
If a persistent backend cannot be initialized, the drop-in logs or warns about the backend failure before falling back to request-local array cache.
WP-CLI cache flushes run directly in the current CLI process and never create temporary PHP endpoints in the web root.
Redis and Memcached use native object-cache backends instead of Symfony internals for WordPress counter semantics. `add`, `replace`, `incr` and `decr` are mapped to backend-native atomic operations where the backend supports them; Redis counters use a Lua script so missing keys are not created and decrements clamp to zero like WordPress expects. Existing non-numeric counter values follow WordPress core semantics and are treated as zero. Flushes use versioned namespaces, so group/runtime invalidation does not depend on scanning or reflecting backend internals. The other Symfony-backed drivers keep best-effort semantics because PSR-6 does not expose cross-process compare-and-swap primitives.

Native Redis and Memcached payloads are signed when `SYMPRESS_CACHE_SECRET`, `APP_SECRET` or `AUTH_KEY` is available. Treat persistent cache backends and the kernel cache directory as trusted infrastructure; do not expose Redis, Memcached, filesystem cache, SQLite/PDO cache files or debug container dumps to untrusted writers.

Operational notes:

- `composer wpstarter dropins` can overwrite an existing `object-cache.php`
  unless `prevent-overwrite` blocks that path. Do not enable this drop-in
  alongside another object-cache drop-in.
- The portable delegator performs a few early `is_file()` checks to resolve the
  Composer autoloader. For standard WP Starter layouts this avoids absolute
  build paths while keeping request overhead small.
- Existing deployments with an older generated drop-in keep using it until
  WP Starter republishes the file or the runtime fallback installer rewrites a
  managed SymPress drop-in.
