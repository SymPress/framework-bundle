<?php

declare(strict_types=1);

namespace SymPress\Framework\DependencyInjection;

use Symfony\Component\DependencyInjection\EnvVarProcessorInterface;

final class WordPressContentUrlProcessor implements EnvVarProcessorInterface
{
    public function getEnv(string $prefix, string $name, \Closure $getEnv): string
    {
        return function_exists('content_url') ? content_url('/') : '';
    }

    public static function getProvidedTypes(): array
    {
        return ['wordpress_content_url' => 'string'];
    }
}
