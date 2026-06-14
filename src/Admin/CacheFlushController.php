<?php

declare(strict_types=1);

namespace SymPress\Framework\Admin;

final class CacheFlushController
{
    public const string ACTION = 'sympress_framework_flush_cache';

    public function flush(): void
    {
        if (function_exists('current_user_can') && !current_user_can('manage_options')) {
            $this->forbidden();
        }

        $nonce = filter_input(INPUT_GET, '_wpnonce', FILTER_SANITIZE_SPECIAL_CHARS);

        if (
            function_exists('wp_verify_nonce')
            && (!is_string($nonce) || !wp_verify_nonce($nonce, self::ACTION))
        ) {
            if (function_exists('wp_nonce_ays')) {
                wp_nonce_ays('');
            }

            $this->forbidden();
        }

        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
        }

        if (function_exists('wp_safe_redirect') && function_exists('wp_get_referer')) {
            wp_safe_redirect(wp_get_referer() ?: $this->fallbackUrl());
            exit;
        }
    }

    private function forbidden(): void
    {
        if (function_exists('wp_die')) {
            wp_die('Forbidden', 403);
        }

        http_response_code(403);
        exit;
    }

    private function fallbackUrl(): string
    {
        if (function_exists('admin_url')) {
            return admin_url();
        }

        return '/';
    }
}
