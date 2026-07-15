# SymPress Framework Bundle agent contract

## Purpose and boundaries

This bundle bridges Symfony FrameworkBundle services into the SymPress kernel and owns the WordPress object-cache drop-in. Cache configuration, backend protocols, signed payloads and drop-in publication are operational and security boundaries.

## Read first

- `Resources/config`: bundle service and package configuration roots.
- `src/DependencyInjection`: Symfony extension and compiler-pass wiring.
- `src/ObjectCache`: configuration, adapters, codec, proxy and drop-in installation.
- `dropin/object-cache.php` and `inc/object-cache-functions.php`: portable WordPress runtime boundary.
- `CONTRIBUTING.md` and the README operational notes before changing cache behavior.

## Verification

- Fast: `composer tests -- --filter <changed subsystem>`.
- Full: `composer qa`.
- Native Redis/Memcached changes: `SYMPRESS_LIVE_CACHE_TESTS=1 composer tests:backends` with both services and PHP extensions available.
- Drop-in changes require tests for ownership/overwrite behavior and a published-file diff.

## Invariants

- Require a strong `APP_SECRET`; never introduce predictable secret fallbacks.
- Never overwrite an unmanaged third-party `object-cache.php`.
- Keep Redis/Memcached counter and group-flush semantics compatible with WordPress.
- Persistent backend failures must degrade safely without exposing secrets or creating web-accessible control endpoints.
- Treat backend stores, cache directories and container dumps as trusted infrastructure.
- Keep service configuration private unless a public entry point is intentional.

## Cross-repository impact

The bundle depends on `sympress/kernel` discovery and container lifecycle. WP Starter may publish the drop-in during setup. Coordinate metadata, service aliases and drop-in path changes with kernel and a runnable WordPress consumer.

## Definition of done

Focused tests and `composer qa` pass; native protocol changes pass the live backend smoke; security/operations docs match behavior; generated or published drop-in content is verified.
