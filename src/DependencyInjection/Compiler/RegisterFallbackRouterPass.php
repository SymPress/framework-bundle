<?php

declare(strict_types=1);

namespace SymPress\Framework\DependencyInjection\Compiler;

use SymPress\Framework\Routing\EmptyRouter;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\Matcher\UrlMatcherInterface;
use Symfony\Component\Routing\RequestContextAwareInterface;
use Symfony\Component\Routing\RouterInterface;

final class RegisterFallbackRouterPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if ($container->hasDefinition('router') || $container->hasAlias('router')) {
            return;
        }

        $container->setDefinition(
            'router',
            (new Definition(EmptyRouter::class))
                ->setPublic(true),
        );

        foreach (
            [
                RouterInterface::class,
                UrlMatcherInterface::class,
                UrlGeneratorInterface::class,
                RequestContextAwareInterface::class,
            ] as $id
        ) {
            if ($container->hasDefinition($id) || $container->hasAlias($id)) {
                continue;
            }

            $container->setAlias($id, 'router')->setPublic(false);
        }
    }
}
