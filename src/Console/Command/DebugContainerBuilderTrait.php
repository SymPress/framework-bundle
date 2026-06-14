<?php

declare(strict_types=1);

namespace SymPress\Framework\Console\Command;

use SymPress\Kernel\Container;
use Symfony\Component\DependencyInjection\ContainerBuilder;

trait DebugContainerBuilderTrait
{
    protected function debugContainerBuilder(Container $container): ContainerBuilder
    {
        $dumpFile = $this->debugDumpFile($container);

        if ($dumpFile !== null) {
            $serializedFile = substr_replace($dumpFile, '.ser', -4);

            if ($this->isTrustedDebugDumpFile($serializedFile, $dumpFile)) {
                $builder = unserialize(file_get_contents($serializedFile) ?: '');

                if ($builder instanceof ContainerBuilder) {
                    return $builder;
                }
            }
        }

        return $container->builder();
    }

    private function debugDumpFile(Container $container): ?string
    {
        if (!$container->hasParameter('debug.container.dump')) {
            return null;
        }

        $dumpFile = $container->getParameter('debug.container.dump');

        return is_string($dumpFile) && $dumpFile !== '' ? $dumpFile : null;
    }

    private function isTrustedDebugDumpFile(string $serializedFile, string $dumpFile): bool
    {
        if (!is_file($serializedFile) || is_link($serializedFile)) {
            return false;
        }

        $realFile = realpath($serializedFile);
        $realDirectory = realpath(dirname($dumpFile));

        if (!is_string($realFile) || !is_string($realDirectory)) {
            return false;
        }

        if (!str_starts_with($realFile, $realDirectory . DIRECTORY_SEPARATOR)) {
            return false;
        }

        $permissions = fileperms($serializedFile);

        if (!is_int($permissions) || ($permissions & 0022) !== 0) {
            return false;
        }

        if (function_exists('posix_geteuid')) {
            $owner = fileowner($serializedFile);

            if (!is_int($owner) || $owner !== posix_geteuid()) {
                return false;
            }
        }

        return true;
    }
}
