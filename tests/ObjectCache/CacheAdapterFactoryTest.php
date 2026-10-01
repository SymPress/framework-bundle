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
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testPublicFilesystemPathsFallBackWithoutWritingCacheFiles(): void
    {
        $directory = sys_get_temp_dir() . '/sympress-public-cache-' . bin2hex(random_bytes(4));
        mkdir($directory);
        define('WP_CONTENT_DIR', $directory);
        $link = $directory . '-link';
        symlink($directory, $link);
        $previousErrorLog = ini_set('error_log', '/dev/null');
        try {
            $backend = (new CacheAdapterFactory())->createPersistent(new CacheConfig('filesystem', ['directory' => $directory . '/cache'], null, false, 0, 'test'));
            self::assertTrue($backend->set('private', 'sensitive'));
            self::assertDirectoryDoesNotExist($directory . '/cache');
            $linked = (new CacheAdapterFactory())->createPersistent(new CacheConfig('filesystem', ['directory' => $link . '/new-cache'], null, false, 0, 'test'));
            self::assertTrue($linked->set('private', 'sensitive'));
            self::assertDirectoryDoesNotExist($directory . '/new-cache');
        } finally {
            ini_set('error_log', is_string($previousErrorLog) ? $previousErrorLog : '');
            unlink($link);
            rmdir($directory);
        }
    }
}
