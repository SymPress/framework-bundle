<?php

declare(strict_types=1);

/*
 * sympress-framework-object-cache
 *
 * Portable object-cache.php delegator for wecodemore/wpstarter and the
 * SymPress runtime fallback installer.
 */

(static function (): void {
    $value = static function (string $name): mixed {
        if (defined($name)) {
            return constant($name);
        }

        $env = getenv($name);

        return $env === false ? null : $env;
    };

    $cacheBypass = $value('SYMPRESS_CACHE_BYPASS');

    if (filter_var($cacheBypass, FILTER_VALIDATE_BOOLEAN)) {
        return;
    }

    $path = static function (mixed $value): ?string {
        if (!is_string($value) || $value === '') {
            return null;
        }

        return rtrim($value, '/\\');
    };

    $file = static function (?string $path): ?string {
        return $path !== null && is_file($path) ? $path : null;
    };

    $projectDirs = [];
    $addProjectDir = static function (?string $dir) use (&$projectDirs): void {
        if ($dir === null || $dir === '') {
            return;
        }

        $projectDirs[] = $dir;
    };

    $addProjectDir($path($value('SYMPRESS_PROJECT_DIR')));
    $addProjectDir($path($value('APP_PROJECT_DIR')));

    $contentDir = defined('WP_CONTENT_DIR') ? $path(constant('WP_CONTENT_DIR')) : $path(__DIR__);

    if ($contentDir !== null) {
        $addProjectDir(dirname($contentDir, 2));
        $addProjectDir(dirname($contentDir));
    }

    if (defined('ABSPATH')) {
        $absolutePath = $path(constant('ABSPATH'));

        if ($absolutePath !== null) {
            $addProjectDir(dirname($absolutePath, 2));
            $addProjectDir(dirname($absolutePath));
        }
    }

    $scanDir = $path(__DIR__);

    for ($depth = 0; $scanDir !== null && $depth < 6; $depth++) {
        $addProjectDir($scanDir);

        $parent = dirname($scanDir);
        if ($parent === $scanDir) {
            break;
        }

        $scanDir = $parent;
    }

    $projectDirs = array_values(array_unique($projectDirs));
    $autoload = $file($path($value('SYMPRESS_COMPOSER_AUTOLOAD')));

    foreach ($projectDirs as $projectDir) {
        $autoload ??= $file($projectDir . '/vendor/autoload.php');

        if ($autoload !== null) {
            break;
        }
    }

    if ($autoload === null) {
        return;
    }

    require_once $autoload;

    $functions = $file($path($value('SYMPRESS_OBJECT_CACHE_FUNCTIONS')));
    $dropInClass = 'SymPress\\Framework\\ObjectCache\\ObjectCacheDropIn';

    if ($functions === null && class_exists($dropInClass)) {
        $reflection = new ReflectionClass($dropInClass);
        $classFile = $reflection->getFileName();

        if (is_string($classFile)) {
            $functions = $file(dirname($classFile, 3) . '/inc/object-cache-functions.php');
        }
    }

    foreach ($projectDirs as $projectDir) {
        $functions ??= $file($projectDir . '/packages/framework-bundle/inc/object-cache-functions.php');
        $functions ??= $file($projectDir . '/packages/framework/inc/object-cache-functions.php');
        $functions ??= $file($projectDir . '/vendor/sympress/framework-bundle/inc/object-cache-functions.php');
        $functions ??= $file($projectDir . '/vendor/sympress/framework/inc/object-cache-functions.php');

        if ($functions !== null) {
            break;
        }
    }

    if ($functions === null) {
        return;
    }

    require_once $functions;
})();
