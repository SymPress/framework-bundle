<?php

declare(strict_types=1);

namespace SymPress\Framework\Tests\ObjectCache;

use PHPUnit\Framework\TestCase;
use SymPress\Framework\ObjectCache\CacheAdapterFactory;
use SymPress\Framework\ObjectCache\CacheConfig;

final class CacheAdapterFactoryTest extends TestCase
{
    public function testRedisConnectionFailureFallsBackToArrayBackend(): void
    {
        $previousErrorLog = ini_set('error_log', '/dev/null');

        try {
            $backend = (new CacheAdapterFactory())->createPersistent(new CacheConfig(
                'redis',
                [],
                'redis://127.0.0.1:1?timeout=0.01',
                true,
                0,
                'sympress.test',
            ));
        } finally {
            ini_set('error_log', is_string($previousErrorLog) ? $previousErrorLog : '');
        }

        self::assertTrue($backend->set('key', 'value'));

        $found = null;
        self::assertSame('value', $backend->get('key', $found));
        self::assertTrue($found);
    }
}
