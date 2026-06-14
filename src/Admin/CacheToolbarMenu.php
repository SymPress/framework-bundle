<?php

declare(strict_types=1);

namespace SymPress\Framework\Admin;

final class CacheToolbarMenu
{
    public function render(object $adminBar): void
    {
        if (!function_exists('current_user_can') || !current_user_can('manage_options')) {
            return;
        }

        if (!method_exists($adminBar, 'add_menu')) {
            return;
        }

        $adminBar->add_menu([
            'id' => 'sympress-framework-cache',
            'parent' => 'top-secondary',
            'title' => 'SymPress Cache',
            'href' => '#',
        ]);

        $adminBar->add_menu([
            'id' => 'sympress-framework-cache-flush',
            'parent' => 'sympress-framework-cache',
            'title' => 'Flush Object Cache',
            'href' => $this->flushUrl(),
        ]);
    }

    private function flushUrl(): string
    {
        if (!function_exists('admin_url') || !function_exists('wp_nonce_url')) {
            return '#';
        }

        $referer = '';

        if (isset($_SERVER['REQUEST_URI']) && is_string($_SERVER['REQUEST_URI'])) {
            $referer = '&_wp_http_referer=' . rawurlencode($_SERVER['REQUEST_URI']);
        }

        return wp_nonce_url(
            admin_url('admin-post.php?action=sympress_framework_flush_cache' . $referer),
            CacheFlushController::ACTION,
        );
    }
}
