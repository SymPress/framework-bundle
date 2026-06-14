<?php

declare(strict_types=1);

namespace SymPress\Framework\ObjectCache;

final class DropInInstaller
{
    private const string MARKER = 'sympress-framework-object-cache';

    public function __construct(
        private readonly string $projectDir,
        private readonly ?string $contentDir = null,
    ) {
    }

    public function install(): bool
    {
        $contentDir = $this->contentDir();

        if ($contentDir === null || !is_dir($contentDir)) {
            return false;
        }

        $target = $contentDir . '/object-cache.php';
        $contents = $this->dropInContents();

        if (is_file($target) && !$this->isManagedDropIn($target)) {
            return true;
        }

        if (is_file($target) && file_get_contents($target) === $contents) {
            return true;
        }

        return $this->writeAtomically($target, $contents);
    }

    private function contentDir(): ?string
    {
        if (is_string($this->contentDir) && $this->contentDir !== '') {
            return rtrim($this->contentDir, '/\\');
        }

        if (defined('WP_CONTENT_DIR')) {
            return rtrim((string) WP_CONTENT_DIR, '/\\');
        }

        return null;
    }

    private function isManagedDropIn(string $target): bool
    {
        $contents = file_get_contents($target);

        return is_string($contents) && str_contains($contents, self::MARKER);
    }

    private function dropInContents(): string
    {
        $template = null;
        $templates = [
            $this->projectDir . '/packages/framework-bundle/dropin/object-cache.php',
            $this->projectDir . '/packages/framework/dropin/object-cache.php',
            $this->projectDir . '/vendor/sympress/framework-bundle/dropin/object-cache.php',
            $this->projectDir . '/vendor/sympress/framework/dropin/object-cache.php',
            dirname(__DIR__, 2) . '/dropin/object-cache.php',
        ];

        foreach ($templates as $candidate) {
            if (is_file($candidate)) {
                $template = $candidate;
                break;
            }
        }

        if ($template === null) {
            throw new \RuntimeException('Unable to find object-cache drop-in template.');
        }

        $contents = file_get_contents($template);

        if (!is_string($contents)) {
            throw new \RuntimeException(sprintf('Unable to read object-cache drop-in template "%s".', $template));
        }

        return $contents;
    }

    private function writeAtomically(string $target, string $contents): bool
    {
        $temporary = tempnam(dirname($target), '.object-cache.');

        if (!is_string($temporary)) {
            return false;
        }

        try {
            if (file_put_contents($temporary, $contents, LOCK_EX) === false) {
                return false;
            }

            return rename($temporary, $target);
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }
}
