<?php

declare(strict_types=1);

namespace SymPress\Framework\Console\Command;

use SymPress\Kernel\Container;
use SymPress\Kernel\Kernel\KernelInterface;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\LogicException;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\StyleInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ConfigurationExtensionInterface;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;

abstract class AbstractConfigCommand extends Command
{
    public function __construct(
        protected readonly KernelInterface $kernel,
        protected readonly Container $container,
    ) {
        parent::__construct();
    }

    protected function containerBuilder(): ContainerBuilder
    {
        $builder = $this->container->builder();

        foreach ($this->kernel->getBundles() as $bundle) {
            $extension = $bundle->getContainerExtension();

            if ($extension instanceof ExtensionInterface && !$builder->hasExtension($extension->getAlias())) {
                $builder->registerExtension($extension);
            }
        }

        return $builder;
    }

    protected function listBundles(OutputInterface|StyleInterface $output): void
    {
        $rows = [];
        $bundles = $this->kernel->getBundles();
        usort($bundles, static fn (object $a, object $b): int => strcmp($a->getName(), $b->getName()));

        foreach ($bundles as $bundle) {
            $extension = $bundle->getContainerExtension();
            $rows[] = [$bundle->getName(), $extension instanceof ExtensionInterface ? $extension->getAlias() : ''];
        }

        $this->renderTable(
            $output,
            'Available registered bundles with their extension alias if available',
            ['Bundle name', 'Extension alias'],
            $rows,
        );
    }

    protected function listNonBundleExtensions(OutputInterface|StyleInterface $output): void
    {
        $bundleExtensions = [];

        foreach ($this->kernel->getBundles() as $bundle) {
            $extension = $bundle->getContainerExtension();

            if ($extension instanceof ExtensionInterface) {
                $bundleExtensions[$extension::class] = true;
            }
        }

        $rows = [];

        foreach ($this->containerBuilder()->getExtensions() as $alias => $extension) {
            if (isset($bundleExtensions[$extension::class])) {
                continue;
            }

            $rows[] = [$alias];
        }

        if ($rows === []) {
            return;
        }

        $this->renderTable(
            $output,
            'Available registered non-bundle extension aliases',
            ['Extension alias'],
            $rows,
        );
    }

    protected function findExtension(string $name): ExtensionInterface
    {
        $guess = null;
        $minScore = \INF;

        foreach ($this->kernel->getBundles() as $bundle) {
            if ($name === $bundle->getName()) {
                $extension = $bundle->getContainerExtension();

                if (!$extension instanceof ExtensionInterface) {
                    throw new \LogicException(sprintf('Bundle "%s" does not have a container extension.', $name));
                }

                return $extension;
            }

            $distance = levenshtein($name, $bundle->getName());

            if ($distance < $minScore) {
                $guess = $bundle->getName();
                $minScore = $distance;
            }
        }

        $builder = $this->containerBuilder();

        if ($builder->hasExtension($name)) {
            return $builder->getExtension($name);
        }

        foreach ($builder->getExtensions() as $extension) {
            if ($name === $extension->getAlias()) {
                return $extension;
            }

            $distance = levenshtein($name, $extension->getAlias());

            if ($distance < $minScore) {
                $guess = $extension->getAlias();
                $minScore = $distance;
            }
        }

        $message = str_ends_with($name, 'Bundle')
            ? sprintf('No extension with alias "%s" is enabled.', $name)
            : sprintf('No extensions with configuration available for "%s".', $name);

        if ($guess !== null && $minScore < 3) {
            $message .= sprintf("\n\nDid you mean \"%s\"?", $guess);
        }

        throw new LogicException($message);
    }

    protected function configuration(ExtensionInterface $extension, ContainerBuilder $builder): ConfigurationInterface
    {
        if ($extension instanceof ConfigurationInterface) {
            return $extension;
        }

        if (!$extension instanceof ConfigurationExtensionInterface) {
            throw new \LogicException(sprintf('The extension with alias "%s" does not have configuration.', $extension->getAlias()));
        }

        $configuration = $extension->getConfiguration($builder->getExtensionConfig($extension->getAlias()), $builder);

        if (!$configuration instanceof ConfigurationInterface) {
            throw new \LogicException(sprintf('Configuration class "%s" should implement ConfigurationInterface in order to be dumpable.', get_debug_type($configuration)));
        }

        return $configuration;
    }

    /**
     * @return array<string, mixed>
     */
    protected function processedConfig(ExtensionInterface $extension, ContainerBuilder $builder): array
    {
        $configuration = $this->configuration($extension, $builder);

        return (new Processor())->processConfiguration(
            $configuration,
            $builder->getExtensionConfig($extension->getAlias()),
        );
    }

    protected function configForPath(mixed $config, string $path, string $alias): mixed
    {
        foreach (explode('.', $path) as $step) {
            if (!is_array($config) || !array_key_exists($step, $config)) {
                throw new LogicException(sprintf('Unable to find configuration for "%s.%s".', $alias, $path));
            }

            $config = $config[$step];
        }

        return $config;
    }

    protected function docUrl(ExtensionInterface $extension, ContainerBuilder $builder): ?string
    {
        $node = $this->configuration($extension, $builder)
            ->getConfigTreeBuilder()
            ->getRootNode()
            ->getNode(true);

        if (!method_exists($node, 'getAttribute')) {
            return null;
        }

        $docUrl = $node->getAttribute('docUrl');

        return is_string($docUrl) ? $docUrl : null;
    }

    /**
     * @return list<string>
     */
    protected function availableExtensions(): array
    {
        return array_keys($this->containerBuilder()->getExtensions());
    }

    /**
     * @return list<string>
     */
    protected function availableBundles(): array
    {
        return array_values(array_map(
            static fn (object $bundle): string => $bundle->getName(),
            $this->kernel->getBundles(),
        ));
    }

    /**
     * @param list<string> $headers
     * @param list<list<string>> $rows
     */
    private function renderTable(
        OutputInterface|StyleInterface $output,
        string $title,
        array $headers,
        array $rows,
    ): void {
        if ($output instanceof StyleInterface) {
            $output->title($title);
            $output->table($headers, $rows);

            return;
        }

        $output->writeln($title);
        (new Table($output))->setHeaders($headers)->setRows($rows)->render();
    }
}
