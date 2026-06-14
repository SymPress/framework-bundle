<?php

declare(strict_types=1);

namespace SymPress\Framework\Tests\ObjectCache;

use PHPUnit\Framework\TestCase;
use SymPress\Framework\ObjectCache\CacheKeyGenerator;

final class CacheKeyGeneratorTest extends TestCase
{
    public function testBlogSpecificGroupsIncludeCurrentBlogScope(): void
    {
        $generator = new CacheKeyGenerator(1);
        $firstBlogKey = $generator->create('post-42', 'posts');

        $generator->switchToBlog(2);
        $secondBlogKey = $generator->create('post-42', 'posts');

        self::assertNotSame($firstBlogKey, $secondBlogKey);
    }

    public function testGlobalGroupsIgnoreBlogScope(): void
    {
        $generator = new CacheKeyGenerator(1);
        $generator->addGlobalGroups(['users']);

        $firstBlogKey = $generator->create('1', 'users');
        $generator->switchToBlog(2);
        $secondBlogKey = $generator->create('1', 'users');

        self::assertSame($firstBlogKey, $secondBlogKey);
    }

    public function testGroupPrefixIsStableAndPsr6Safe(): void
    {
        $prefix = (new CacheKeyGenerator())->groupPrefix('site-transient');

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_.-]+$/', $prefix);
        self::assertStringStartsWith('g.site-transient.', $prefix);
    }
}
