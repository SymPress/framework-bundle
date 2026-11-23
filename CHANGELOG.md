# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html)
where applicable.

## 1.0.12 — 2026-11-23

- Treat object-cache payloads that fail signature or class validation as misses in the native Redis and Memcached backends and delete them, so `wp_cache_add()` refills the cache after rotating `APP_SECRET`/`SYMPRESS_CACHE_SECRET` instead of failing forever.
- Convert Memcached expirations above 30 days to absolute timestamps; long-lived transients no longer expire immediately.
- Clone objects in the request cache on set and get, like WordPress core, and keep cached `null` values in `wp_cache_get_multiple()`.
- Read Redis/Memcached namespace versions once per request (one round trip per `get`, one per `get_multiple` instead of 2n+1) and use random version tokens, so an evicted version key no longer revives entries from before a flush.
- Remove Redis entries of superseded namespace versions during the scheduled purge (`SCAN`/`UNLINK`), so repeated flushes no longer grow Redis without bound.
- Document that the payload signature prevents object injection but does not bind the cache key.
- Let Dependabot update the GitHub Actions pins.

## 1.0.11 — 2026-11-16

- Require a super admin to flush the object cache from the admin bar on multisite; the persistent cache is shared network-wide, so sub-site admins (`manage_options`) can no longer flush it. The toolbar entry is hidden for them as well.
- Install the fallback `object-cache.php` drop-in with mode 0644 instead of tempnam()'s 0600, so PHP-FPM can read it when the installer runs as another user.
- Pin the reusable `sympress/workflows` CI workflows to v1.3.2.

## 1.0.10 — 2026-11-14

- Register Symfony's ConsoleBundle with FrameworkBundle so debug containers (`WP_DEBUG`) compile when `symfony/stopwatch` is installed; previously `debug.console.argument_resolver` failed on the missing `console.argument_resolver` service.
- Verify debug-mode container compilation in the kernel test suite.

## 1.0.9 — 2026-11-06

- Use the Symfony 8.1.11 security patches and require Kernel 1.1.14 for its HttpFoundation security floor.

## 1.0.8 — 2026-10-23

- Rebuild container diagnostics on a cloned kernel so lint/debug commands preserve active containers, booted bundles and final shutdown cleanup.
- Add a regression that verifies live container and bundle identity and successful shutdown after diagnostics.

## 1.0.7 — 2026-10-16

- Require Symfony ExpressionLanguage 8.1.9 and YAML 8.1.6 or newer to exclude CVE-2026-109449 and CVE-2026-109413.
- Require SymPress Kernel 1.1.10 or newer to retain the corrected HttpFoundation 8.1.9 minimum in lowest-version installations.
- Require Symfony FrameworkBundle 8.1.8 or newer so the native development cache warmer is registered when the profiler is disabled.
- Verify dependency version boundaries and rejection of excessively nested expressions and inline YAML collections.

## 1.0.6 — 2026-10-09

- Require maintained Symfony Routing and YAML 8.1 or newer, excluding the vulnerable 8.0 releases covered by the May 2026 advisories.
- Require Symfony DependencyInjection 8.1.8 or newer to preserve reused environment placeholders during extension configuration and container dumping.

## 1.0.5 — 2026-10-05

- Reconstruct complete configuration for lint/debug commands when the kernel reuses a compiled container. Avoid compiling a partial warm facade that lacks Twig services; retain the running runtime container.
- Verify complete Demo deployment against real SSH, MariaDB and FPM after an immutable warm-cache hit.

## 1.0.4 — 2026-10-03

- Keep upgrades with missing or short APP_SECRET available on existing kernels: WordPress object caches and Symfony application pools use request memory until a strong signing secret is provided; report the production requirement through validation without per-request warning noise.
- Authenticate Symfony application pool payloads before deserialization across persistent adapters and configured marshallers; preserve trusted Symfony system/compiled-code caches and discard unsigned legacy entries as misses.
- Use a stable literal SYMPRESS_PROJECT_DIR for WordPress signing/namespaces and Symfony cache pool seeds while retaining active-release package, configuration and autoloader discovery.
- Allow explicitly reviewed plugin/WooCommerce object classes only after signed payload authentication.

## 1.0.3 — 2026-10-02

- Scope WordPress object-cache namespaces and signing keys to the project and environment, including explicitly configured prefix labels.
- Authenticate filesystem, SQLite/PDO and APCu serialized payloads before decoding, matching the signed native Redis/Memcached boundary.
- Require a strong independent application/cache secret and isolate default temporary stores by user and namespace; weak configuration falls back safely to request memory.
