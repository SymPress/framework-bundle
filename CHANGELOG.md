# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html)
where applicable.

## Unreleased

- Keep upgrades with missing or short APP_SECRET available on existing kernels: WordPress object caches and Symfony application pools use request memory until a strong signing secret is provided; report the production requirement through validation without per-request warning noise.
- Authenticate Symfony application pool payloads before deserialization across persistent adapters and configured marshallers; preserve trusted Symfony system/compiled-code caches and discard unsigned legacy entries as misses.
- Use a stable literal SYMPRESS_PROJECT_DIR for WordPress signing/namespaces and Symfony cache pool seeds while retaining active-release package, configuration and autoloader discovery.
- Allow explicitly reviewed plugin/WooCommerce object classes only after signed payload authentication.

## 1.0.3 — 2026-10-02

- Scope WordPress object-cache namespaces and signing keys to the project and environment, including explicitly configured prefix labels.
- Authenticate filesystem, SQLite/PDO and APCu serialized payloads before decoding, matching the signed native Redis/Memcached boundary.
- Require a strong independent application/cache secret and isolate default temporary stores by user and namespace; weak configuration falls back safely to request memory.
