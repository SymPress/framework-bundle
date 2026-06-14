<?php

declare(strict_types=1);

final class WP_CLI
{
    /**
     * @param array<string, mixed>|callable|object|string $callable
     */
    public static function add_command(string $name, array|callable|object|string $callable): void
    {
    }

    public static function error(string $message): void
    {
    }

    public static function halt(int $returnCode): void
    {
    }

    public static function success(string $message): void
    {
    }

    public static function warning(string $message): void
    {
    }
}
