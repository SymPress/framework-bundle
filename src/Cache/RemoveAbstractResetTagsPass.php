<?php

declare(strict_types=1);

namespace SymPress\Framework\Cache;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class RemoveAbstractResetTagsPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        foreach ($container->getDefinitions() as $definition) {
            if (!$definition->isAbstract() || !$definition->hasTag('kernel.reset')) {
                continue;
            }

            $definition->clearTag('kernel.reset');
        }
    }
}
