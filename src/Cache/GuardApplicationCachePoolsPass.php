<?php

declare(strict_types=1);

namespace SymPress\Framework\Cache;

use Symfony\Component\Cache\Adapter\AbstractAdapter;
use Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

final class GuardApplicationCachePoolsPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter('framework.cache.secret')) {
            return;
        }
        foreach ($container->findTaggedServiceIds('cache.pool') as $id => $tags) {
            $definition = $container->getDefinition($id);
            // Symfony's system PHP caches are trusted compiled application code, not serialized app payloads.
            if ($definition->isAbstract() || $definition->getFactory() === [AbstractAdapter::class, 'createSystemCache']) {
                continue;
            }
            $inner = '.sympress.persistent.' . $id;
            $persistent = clone $definition;
            $persistent->setPublic(false)->setTags([]);
            $container->setDefinition($inner, $persistent);
            $definition->setFactory([GuardedCachePoolFactory::class, 'create']);
            $definition->setArguments(['%framework.cache.secret%', new ServiceClosureArgument(new Reference($inner))]);
            $definition->setMethodCalls([]);
        }
    }
}
