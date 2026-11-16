<?php

declare(strict_types=1);

namespace SymPress\Framework\Tests\Admin;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use SymPress\Framework\Admin\CacheFlushController;
use SymPress\Framework\Admin\CacheToolbarMenu;

#[RunTestsInSeparateProcesses]
final class CacheFlushAccessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Functions\when('current_user_can')->alias(static fn (string $capability): bool => $capability === 'manage_options');
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function testSubSiteAdminCannotFlushNetworkCache(): void
    {
        Functions\when('is_multisite')->justReturn(true);
        Functions\when('is_super_admin')->justReturn(false);
        Functions\expect('wp_cache_flush')->never();
        Functions\when('wp_die')->alias(static function (): never {
            throw new \RuntimeException('forbidden');
        });

        try {
            (new CacheFlushController())->flush();
            self::fail('Sub-site admin was allowed to flush the network cache.');
        } catch (\RuntimeException $exception) {
            self::assertSame('forbidden', $exception->getMessage());
        }
    }

    public function testSubSiteAdminDoesNotSeeFlushMenu(): void
    {
        Functions\when('is_multisite')->justReturn(true);
        Functions\when('is_super_admin')->justReturn(false);

        $adminBar = new class () {
            /** @var list<array<string, string>> */
            public array $menus = [];

            /** @param array<string, string> $menu */
            public function add_menu(array $menu): void
            {
                $this->menus[] = $menu;
            }
        };

        (new CacheToolbarMenu())->render($adminBar);

        self::assertSame([], $adminBar->menus);
    }

    public function testSuperAdminSeesFlushMenu(): void
    {
        Functions\when('is_multisite')->justReturn(true);
        Functions\when('is_super_admin')->justReturn(true);

        $adminBar = new class () {
            /** @var list<array<string, string>> */
            public array $menus = [];

            /** @param array<string, string> $menu */
            public function add_menu(array $menu): void
            {
                $this->menus[] = $menu;
            }
        };

        (new CacheToolbarMenu())->render($adminBar);

        self::assertCount(2, $adminBar->menus);
    }

    public function testSingleSiteAdminCanFlush(): void
    {
        Functions\when('is_multisite')->justReturn(false);

        self::assertTrue(CacheFlushController::currentUserCanFlush());
    }
}
