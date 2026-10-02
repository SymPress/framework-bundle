# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html)
where applicable.

## 1.0.3 — 2026-10-02

- Scope WordPress object-cache namespaces and signing keys to the project and environment, including explicitly configured prefix labels.
- Authenticate filesystem, SQLite/PDO and APCu serialized payloads before decoding, matching the signed native Redis/Memcached boundary.
- Require a strong independent application/cache secret and isolate default temporary stores by user and namespace; weak configuration falls back safely to request memory.

## Unreleased

- Initial Framework Bundle package, QA workflow and repository documentation.
