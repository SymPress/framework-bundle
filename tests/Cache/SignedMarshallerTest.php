<?php

declare(strict_types=1);

namespace SymPress\Framework\Tests\Cache;

use PHPUnit\Framework\TestCase;
use SymPress\Framework\Cache\SignedMarshaller;
use Symfony\Component\Cache\Exception\CacheException;
use Symfony\Component\Cache\Marshaller\DefaultMarshaller;

final class SignedMarshallerTest extends TestCase
{
    public function testAuthenticationPrecedesObjectRestorationAndRejectsLegacyPayloads(): void
    {
        $marshaller = new SignedMarshaller(str_repeat('test-secret-', 4), 'stable-project', new DefaultMarshaller());
        $failed = null;
        $encoded = $marshaller->marshall(['value' => new SignedCacheFixture()], $failed);
        SignedCacheFixture::$wakes = 0;
        foreach ([serialize(new SignedCacheFixture()), $encoded['value'] . 'tampered'] as $untrusted) {
            try {
                $marshaller->unmarshall($untrusted);
                self::fail('Unsigned/tampered payload was accepted.');
            } catch (CacheException) {
                self::assertSame(0, SignedCacheFixture::$wakes);
            }
        }
        self::assertInstanceOf(SignedCacheFixture::class, $marshaller->unmarshall($encoded['value']));
        self::assertSame(1, SignedCacheFixture::$wakes);
        $this->expectException(CacheException::class);
        (new SignedMarshaller(str_repeat('test-secret-', 4), 'other-project', new DefaultMarshaller()))->unmarshall($encoded['value']);
    }
}

final class SignedCacheFixture
{
    public static int $wakes = 0;

    public function __wakeup(): void
    {
        self::$wakes++;
    }
}
