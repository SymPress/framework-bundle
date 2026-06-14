# Contributing

Thanks for taking the time to improve SymPress Framework Bundle.

## Local Setup

```bash
composer install
composer qa
```

The package uses PHP 8.5, Symfony FrameworkBundle, Symfony Cache, the SymPress
kernel, PHPUnit, PHPStan, and PHPCS with the SymPress coding standards.

## Pull Requests

- Keep pull requests focused on one behavior or documentation change.
- Add or update tests for cache, drop-in, compiler-pass, or configuration changes.
- Run the available checks before opening a pull request.
- Use Conventional Commits for commit messages, for example
  `feat(framework-bundle): add cache pool configuration`.

## Coding Guidelines

- Keep WordPress runtime behavior isolated behind adapters, hooks, or drop-in services.
- Prefer Symfony container configuration over runtime service lookups.
- Preserve upstream Symfony FrameworkBundle aliases where projects expect them.
- Treat persistent cache backends and generated container dumps as trusted infrastructure.
