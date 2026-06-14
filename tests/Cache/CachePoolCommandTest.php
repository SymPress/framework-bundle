<?php

declare(strict_types=1);

namespace SymPress\Framework\Tests\Cache;

use PHPUnit\Framework\TestCase;
use SymPress\Framework\Cache\CachePoolRegistry;
use SymPress\Framework\Cache\Command\CachePoolClearCommand;
use SymPress\Framework\Cache\Command\CachePoolInvalidateTagsCommand;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class CachePoolCommandTest extends TestCase
{
    public function testClearCommandSupportsAllAndExcludeOptions(): void
    {
        $first = new ArrayAdapter();
        $second = new ArrayAdapter();
        $first->save($first->getItem('first')->set('cached'));
        $second->save($second->getItem('second')->set('cached'));

        $tester = new CommandTester(new CachePoolClearCommand(new CachePoolRegistry([
            'cache.first' => $first,
            'cache.second' => $second,
        ])));

        self::assertSame(Command::SUCCESS, $tester->execute([
            '--all' => true,
            '--exclude' => ['cache.second'],
        ]));

        self::assertFalse($first->hasItem('first'));
        self::assertTrue($second->hasItem('second'));
    }

    public function testClearCommandFailsForUnknownPools(): void
    {
        $tester = new CommandTester(new CachePoolClearCommand(new CachePoolRegistry([
            'cache.known' => new ArrayAdapter(),
        ])));

        self::assertSame(Command::FAILURE, $tester->execute([
            'pools' => ['cache.missing'],
        ]));
        self::assertStringContainsString('Unknown cache pool(s): cache.missing.', $tester->getDisplay());
    }

    public function testInvalidateTagsCommandFailsForUnknownTaggablePool(): void
    {
        $tester = new CommandTester(new CachePoolInvalidateTagsCommand(new CachePoolRegistry(
            ['cache.plain' => new ArrayAdapter()],
            ['cache.tags' => new TagAwareAdapter(new ArrayAdapter())],
        )));

        self::assertSame(Command::FAILURE, $tester->execute([
            'tags' => ['homepage'],
            '--pool' => ['cache.missing'],
        ]));
        self::assertStringContainsString(
            'Cache pool "cache.missing" is not tag-aware or does not exist.',
            $tester->getDisplay(),
        );
    }
}
