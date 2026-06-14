<?php

declare(strict_types=1);

namespace SymPress\Framework\Cli;

final class WpCliRegistrar
{
    public function register(): void
    {
        if (!defined('WP_CLI') || !WP_CLI || !class_exists('WP_CLI')) {
            return;
        }

        \WP_CLI::add_command('sympress cache', WpCliCommand::class);
    }
}
