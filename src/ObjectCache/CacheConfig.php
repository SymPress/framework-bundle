<?php

declare(strict_types=1);

namespace SymPress\Framework\ObjectCache;

final class CacheConfig
{
    /**
     * @param array<string, mixed> $driverArgs
     */
    public function __construct(
        public readonly string $driver,
        public readonly array $driverArgs,
        public readonly ?string $dsn,
        public readonly bool $inMemory,
        public readonly int $purgeInterval,
        public readonly string $prefix,
        public readonly ?string $secret = null,
    ) {
    }
}
