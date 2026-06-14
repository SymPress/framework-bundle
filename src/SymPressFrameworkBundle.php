<?php

declare(strict_types=1);

namespace SymPress\Framework;

use SymPress\Framework\Cache\FrameworkCacheConfigurationPass;
use SymPress\Framework\Cache\RemoveAbstractResetTagsPass;
use SymPress\Framework\DependencyInjection\Compiler\RegisterFallbackRouterPass;
use SymPress\Kernel\Bundle\AbstractBundle;
use Symfony\Component\Cache\DependencyInjection\CachePoolClearerPass;
use Symfony\Component\Cache\DependencyInjection\CachePoolPass;
use Symfony\Component\Cache\DependencyInjection\CachePoolPrunerPass;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle as SymfonyFrameworkBundle;

final class SymPressFrameworkBundle extends AbstractBundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        if (class_exists(SymfonyFrameworkBundle::class)) {
            (new SymfonyFrameworkBundle())->build($container);
            $container->addCompilerPass(
                new FrameworkCacheConfigurationPass(),
                PassConfig::TYPE_BEFORE_OPTIMIZATION,
                100,
            );
            $container->addCompilerPass(new RemoveAbstractResetTagsPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, -31);
            $container->addCompilerPass(new RegisterFallbackRouterPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, -1000);

            return;
        }

        $container->addCompilerPass(
            new FrameworkCacheConfigurationPass(),
            PassConfig::TYPE_BEFORE_OPTIMIZATION,
            100,
        );
        $container->addCompilerPass(new CachePoolPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION);
        $container->addCompilerPass(new RemoveAbstractResetTagsPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, -31);
        $container->addCompilerPass(new RegisterFallbackRouterPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, -1000);
        $container->addCompilerPass(new CachePoolPrunerPass(), PassConfig::TYPE_BEFORE_REMOVING);
        $container->addCompilerPass(new CachePoolClearerPass(), PassConfig::TYPE_BEFORE_REMOVING, -100);
    }
}
