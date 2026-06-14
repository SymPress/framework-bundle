<?php

declare(strict_types=1);

namespace SymPress\Framework\ObjectCache\Hook;

use SymPress\Framework\ObjectCache\DropInInstaller;

final class DropInInstallerHook
{
    public function __construct(
        private readonly DropInInstaller $installer,
    ) {
    }

    public function install(): void
    {
        if (function_exists('wp_installing') && wp_installing()) {
            return;
        }

        $this->installer->install();
    }
}
