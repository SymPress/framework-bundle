<?php

declare(strict_types=1);

namespace SymPress\Framework\DependencyInjection;

use SymPress\Kernel\Console\DebugContainerCommand as KernelDebugContainerCommand;
use SymPress\Kernel\Console\LintContainerCommand as KernelLintContainerCommand;
use Symfony\Bundle\FrameworkBundle\DependencyInjection\FrameworkExtension;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class SymPressFrameworkExtension extends FrameworkExtension
{
    public function getAlias(): string
    {
        return 'framework';
    }

    /**
     * @param array<int, array<string, mixed>> $configs
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        if (!$container->hasParameter('kernel.charset')) {
            $container->setParameter('kernel.charset', 'UTF-8');
        }

        $configuration = $this->getConfiguration($configs, $container);

        if ($configuration !== null) {
            $config = (new Processor())->processConfiguration($configuration, $configs);
            $container->setParameter('framework.cache', $config['cache'] ?? []);
        }

        parent::load($configs, $container);

        $this->removeUnavailableAutoconfiguration($container);
        $this->removeSymfonyKernelConsoleCommands($container);

        if (
            !class_exists(\Symfony\Component\Messenger\Attribute\AsMessageHandler::class)
            && $container->hasDefinition('cache.early_expiration_handler')
        ) {
            $container->removeDefinition('cache.early_expiration_handler');
        }
    }

    private function removeSymfonyKernelConsoleCommands(ContainerBuilder $container): void
    {
        foreach (
            [
                'console.command.cache_clear',
                'console.command.cache_pool_clear',
                'console.command.cache_pool_delete',
                'console.command.cache_pool_invalidate_tags',
                'console.command.cache_pool_list',
                'console.command.cache_pool_prune',
                'console.command.cache_warmup',
                'console.command.config_debug',
                'console.command.config_dump_reference',
                'console.command.container_debug',
                'console.command.container_lint',
                'console.command.debug_autowiring',
                KernelDebugContainerCommand::class,
                KernelLintContainerCommand::class,
            ] as $id
        ) {
            if (!$container->hasDefinition($id)) {
                continue;
            }

            $container->removeDefinition($id);
        }
    }

    private function removeUnavailableAutoconfiguration(ContainerBuilder $container): void
    {
        $this->filterContainerBuilderMap(
            $container,
            'autoconfiguredAttributes',
            static fn (string $class): bool => class_exists($class),
        );
        $this->filterContainerBuilderMap(
            $container,
            'autoconfiguredInstanceof',
            static fn (string $class): bool => interface_exists($class) || class_exists($class),
        );
    }

    /**
     * @param callable(string): bool $keep
     */
    private function filterContainerBuilderMap(ContainerBuilder $container, string $property, callable $keep): void
    {
        if (!property_exists(ContainerBuilder::class, $property)) {
            return;
        }

        $reflection = new \ReflectionProperty(ContainerBuilder::class, $property);
        $values = $reflection->getValue($container);

        if (!is_array($values)) {
            return;
        }

        foreach (array_keys($values) as $class) {
            if (!is_string($class) || $keep($class)) {
                continue;
            }

            unset($values[$class]);
        }

        $reflection->setValue($container, $values);
    }
}
