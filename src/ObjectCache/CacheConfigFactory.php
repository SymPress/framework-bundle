<?php

declare(strict_types=1);

namespace SymPress\Framework\ObjectCache;

final class CacheConfigFactory
{
    private const int DEFAULT_PURGE_INTERVAL = 43200;

    public function create(): CacheConfig
    {
        $driver = $this->stringValue('SYMPRESS_CACHE_DRIVER')
            ?? 'array';

        $driverArgs = $this->arrayValue('SYMPRESS_CACHE_DRIVER_ARGS') ?? [];

        return new CacheConfig(
            $this->normalizeDriver($driver),
            $driverArgs,
            $this->stringValue('SYMPRESS_CACHE_DSN') ?? $this->dsnFromArgs($driverArgs),
            $this->boolValue('SYMPRESS_CACHE_IN_MEMORY') ?? true,
            $this->intValue('SYMPRESS_CACHE_PURGE_INTERVAL') ?? self::DEFAULT_PURGE_INTERVAL,
            $this->stringValue('SYMPRESS_CACHE_PREFIX') ?? 'sympress.wp',
            $this->stringValue('SYMPRESS_CACHE_SECRET')
                ?? $this->stringValue('APP_SECRET')
                ?? $this->stringValue('AUTH_KEY'),
        );
    }

    private function normalizeDriver(string $driver): string
    {
        $driver = trim($driver, "\\ \t\n\r\0\x0B");
        $lower = strtolower($driver);

        return match ($lower) {
            'apc', 'apcu' => 'apcu',
            'file', 'fs', 'filesystem' => 'filesystem',
            'sqlite' => 'sqlite',
            'memcache', 'memcached' => 'memcached',
            'redis', 'valkey' => 'redis',
            'ephemeral', 'memory', 'array' => 'array',
            'null', 'none', 'disabled' => 'null',
            default => $lower,
        };
    }

    /**
     * @param array<string, mixed> $args
     */
    private function dsnFromArgs(array $args): ?string
    {
        $dsn = $args['dsn'] ?? $args['url'] ?? $args['server'] ?? null;

        return is_string($dsn) && $dsn !== '' ? $dsn : null;
    }

    private function stringValue(string $name): ?string
    {
        if (defined($name)) {
            $value = constant($name);

            return is_scalar($value) ? (string) $value : null;
        }

        $value = getenv($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function boolValue(string $name): ?bool
    {
        $value = $this->stringValue($name);

        if ($value === null) {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    private function intValue(string $name): ?int
    {
        $value = $this->stringValue($name);

        if ($value === null || !is_numeric($value)) {
            return null;
        }

        return max(0, (int) $value);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function arrayValue(string $name): ?array
    {
        $value = $this->stringValue($name);

        if ($value === null) {
            return null;
        }

        $decoded = base64_decode($value, true);

        if (is_string($decoded) && base64_encode($decoded) === $value) {
            $value = $decoded;
        }

        $json = json_decode($value, true);

        if (is_array($json)) {
            return $json;
        }

        return [];
    }
}
