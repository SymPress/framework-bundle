<?php

declare(strict_types=1);

namespace {
    function content_url(string $path): string
    {
        return ($GLOBALS['sympress_content_url'] ?? 'https://default.example/content') . $path;
    }
}

namespace SymPress\Framework\Tests\DependencyInjection {
    use PHPUnit\Framework\TestCase;
    use SymPress\Framework\DependencyInjection\WordPressContentUrlProcessor;

    final class WordPressContentUrlProcessorTest extends TestCase
    {
        public function testResolvesUrlAtRuntimeAfterConstruction(): void
        {
            $processor = new WordPressContentUrlProcessor();
            $GLOBALS['sympress_content_url'] = 'https://build.example/content';
            $resolve = static fn (): string => '';
            self::assertSame('https://build.example/content/', $processor->getEnv('wordpress_content_url', 'unused', $resolve));
            $GLOBALS['sympress_content_url'] = 'https://runtime.example/content';
            self::assertSame('https://runtime.example/content/', $processor->getEnv('wordpress_content_url', 'unused', $resolve));
            unset($GLOBALS['sympress_content_url']);
        }
    }
}
