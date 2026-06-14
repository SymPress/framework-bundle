<?php

declare(strict_types=1);

namespace SymPress\Framework\Tests\ObjectCache;

use PHPUnit\Framework\TestCase;
use SymPress\Framework\ObjectCache\DropInInstaller;

final class DropInInstallerTest extends TestCase
{
    public function testInstallsManagedObjectCacheDropIn(): void
    {
        $contentDir = sys_get_temp_dir() . '/sympress-framework-test-' . bin2hex(random_bytes(4));
        mkdir($contentDir);

        try {
            $installer = new DropInInstaller(dirname(__DIR__, 2), $contentDir);

            self::assertTrue($installer->install());
            self::assertFileExists($contentDir . '/object-cache.php');
            self::assertStringContainsString(
                'sympress-framework-object-cache',
                (string) file_get_contents($contentDir . '/object-cache.php'),
            );
            self::assertSame(
                file_get_contents(dirname(__DIR__, 2) . '/dropin/object-cache.php'),
                file_get_contents($contentDir . '/object-cache.php'),
            );
        } finally {
            if (is_file($contentDir . '/object-cache.php')) {
                unlink($contentDir . '/object-cache.php');
            }

            if (is_dir($contentDir)) {
                rmdir($contentDir);
            }
        }
    }

    public function testDoesNotRewriteUnchangedManagedObjectCacheDropIn(): void
    {
        $contentDir = sys_get_temp_dir() . '/sympress-framework-test-' . bin2hex(random_bytes(4));
        mkdir($contentDir);

        try {
            $installer = new DropInInstaller(dirname(__DIR__, 2), $contentDir);
            $target = $contentDir . '/object-cache.php';

            self::assertTrue($installer->install());
            self::assertTrue(touch($target, 1234567890));
            clearstatcache(true, $target);

            self::assertTrue($installer->install());
            clearstatcache(true, $target);
            self::assertSame(1234567890, filemtime($target));
        } finally {
            if (is_file($contentDir . '/object-cache.php')) {
                unlink($contentDir . '/object-cache.php');
            }

            if (is_dir($contentDir)) {
                rmdir($contentDir);
            }
        }
    }

    public function testInstalledDropInCanBeBypassedWithSympressConstant(): void
    {
        $contentDir = sys_get_temp_dir() . '/sympress-framework-test-' . bin2hex(random_bytes(4));
        mkdir($contentDir);

        try {
            $installer = new DropInInstaller(dirname(__DIR__, 2), $contentDir);

            self::assertTrue($installer->install());

            $code = sprintf(
                <<<'PHP'
define('SYMPRESS_CACHE_BYPASS', true);
require %s;
exit(function_exists('wp_cache_init') ? 1 : 0);
PHP,
                var_export($contentDir . '/object-cache.php', true),
            );

            self::assertSame(0, $this->runPhp($code));
        } finally {
            if (is_file($contentDir . '/object-cache.php')) {
                unlink($contentDir . '/object-cache.php');
            }

            if (is_dir($contentDir)) {
                rmdir($contentDir);
            }
        }
    }

    public function testInstalledDropInInitializesWithConfiguredProjectDir(): void
    {
        $rootDir = dirname(__DIR__, 4);
        $rootAutoload = $rootDir . '/vendor/autoload.php';

        if (!is_file($rootAutoload)) {
            self::markTestSkipped('Root Composer autoload file is not available.');
        }

        $contentDir = sys_get_temp_dir() . '/sympress-framework-test-' . bin2hex(random_bytes(4));
        mkdir($contentDir);

        try {
            $installer = new DropInInstaller($rootDir, $contentDir);

            self::assertTrue($installer->install());

            $code = sprintf(
                <<<'PHP'
define('SYMPRESS_PROJECT_DIR', %s);
define('SYMPRESS_CACHE_DRIVER', 'array');
require %s;
if (!function_exists('wp_cache_init')) {
    exit(1);
}
wp_cache_init();
exit(isset($GLOBALS['wp_object_cache']) ? 0 : 2);
PHP,
                var_export($rootDir, true),
                var_export($contentDir . '/object-cache.php', true),
            );

            self::assertSame(0, $this->runPhp($code));
        } finally {
            if (is_file($contentDir . '/object-cache.php')) {
                unlink($contentDir . '/object-cache.php');
            }

            if (is_dir($contentDir)) {
                rmdir($contentDir);
            }
        }
    }

    public function testDropInCopiedByWpStarterCanResolveProjectLayout(): void
    {
        $rootDir = dirname(__DIR__, 4);

        if (!is_file($rootDir . '/vendor/autoload.php')) {
            self::markTestSkipped('Root Composer autoload file is not available.');
        }

        $projectDir = sys_get_temp_dir() . '/sympress-framework-wpstarter-' . bin2hex(random_bytes(4));
        $contentDir = $projectDir . '/public/wp-content';

        mkdir($contentDir, 0777, true);

        try {
            if (!@symlink($rootDir . '/vendor', $projectDir . '/vendor')) {
                self::markTestSkipped('Symlinks are not available.');
            }

            if (!@symlink($rootDir . '/packages', $projectDir . '/packages')) {
                self::markTestSkipped('Symlinks are not available.');
            }

            self::assertTrue(
                copy(dirname(__DIR__, 2) . '/dropin/object-cache.php', $contentDir . '/object-cache.php'),
            );

            $code = sprintf(
                <<<'PHP'
define('WP_CONTENT_DIR', %s);
define('SYMPRESS_CACHE_DRIVER', 'array');
require %s;
if (!function_exists('wp_cache_init')) {
    exit(1);
}
wp_cache_init();
exit(isset($GLOBALS['wp_object_cache']) ? 0 : 2);
PHP,
                var_export($contentDir, true),
                var_export($contentDir . '/object-cache.php', true),
            );

            self::assertSame(0, $this->runPhp($code));
        } finally {
            $this->removePath($projectDir);
        }
    }

    private function runPhp(string $code): int
    {
        $command = escapeshellarg(\PHP_BINARY) . ' -d display_errors=stderr -r ' . escapeshellarg($code);
        $output = [];
        $exitCode = 0;
        exec($command, $output, $exitCode);

        return $exitCode;
    }

    private function removePath(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }

        if (!is_dir($path)) {
            return;
        }

        $entries = scandir($path);

        if (!is_array($entries)) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $this->removePath($path . '/' . $entry);
        }

        rmdir($path);
    }
}
