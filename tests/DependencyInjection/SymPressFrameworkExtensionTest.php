<?php

declare(strict_types=1);

namespace SymPress\Framework\Tests\DependencyInjection;

use PHPUnit\Framework\TestCase;
use SymPress\Framework\DependencyInjection\SymPressFrameworkExtension;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class SymPressFrameworkExtensionTest extends TestCase
{
    public function testLoadsSymfonyFrameworkBundleServicesBeyondCache(): void
    {
        $container = $this->container();

        (new SymPressFrameworkExtension())->load([
            [
                'secret' => 'test-secret',
            ],
        ], $container);

        self::assertSame('framework', (new SymPressFrameworkExtension())->getAlias());
        self::assertTrue($container->hasDefinition('request_stack'));
        self::assertTrue($container->hasDefinition('http_kernel'));
        self::assertTrue($container->hasDefinition('controller_resolver'));
        self::assertTrue($container->hasDefinition('cache.app'));
        self::assertSame('test-secret', $container->getParameter('kernel.secret'));
        self::assertIsArray($container->getParameter('framework.cache'));
    }

    private function container(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', false);
        $container->setParameter('kernel.project_dir', dirname(__DIR__, 2));
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.cache_dir', sys_get_temp_dir() . '/sympress-framework-extension-cache');
        $container->setParameter('kernel.build_dir', sys_get_temp_dir() . '/sympress-framework-extension-build');
        $container->setParameter('kernel.logs_dir', sys_get_temp_dir() . '/sympress-framework-extension-log');
        $container->setParameter('kernel.container_class', 'KernelContainer');

        return $container;
    }
}
