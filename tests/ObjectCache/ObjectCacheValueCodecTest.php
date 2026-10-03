<?php

declare(strict_types=1);

namespace SymPress\Framework\Tests\ObjectCache;

use PHPUnit\Framework\TestCase;
use SymPress\Framework\ObjectCache\ObjectCacheValueCodec;

final class ObjectCacheValueCodecTest extends TestCase
{
    public function testSignedPayloadsRoundTripObjects(): void
    {
        $codec = new ObjectCacheValueCodec('secret');
        $object = new \stdClass();
        $object->name = 'cached';

        $decoded = $codec->decode($codec->encode($object));

        self::assertInstanceOf(\stdClass::class, $decoded);
        self::assertSame('cached', $decoded->name);
    }

    public function testTamperedSignedPayloadsAreRejected(): void
    {
        $codec = new ObjectCacheValueCodec('secret');
        $payload = $codec->encode(['cached' => true]);
        $tampered = substr_replace($payload, substr($payload, -1) === 'A' ? 'B' : 'A', -1);

        self::assertFalse($codec->decode($tampered));
    }
    public function testUnsignedLegacyValuesAreRejectedWithConfiguredSecret(): void
    {
        $signed = new ObjectCacheValueCodec('secret');
        $legacy = new ObjectCacheValueCodec();
        self::assertFalse($signed->decode($legacy->encode(['sensitive' => true])));
        self::assertSame(['sensitive' => true], $legacy->decode($legacy->encode(['sensitive' => true])));
        self::assertSame(42, $signed->decode($signed->encode(42)));
        foreach (['sympress-cache-v2:bad', 'sympress-cache-v2:bad:%%%'] as $malformed) {
            self::assertFalse($signed->decode($malformed));
        }
    }

    public function testDisallowedMagicObjectsCannotBeInstantiated(): void
    {
        $object = new CacheWakeupProbe();
        $codec = new ObjectCacheValueCodec('secret');
        self::assertFalse($codec->decode($codec->encode(['nested' => $object])));
        self::assertSame(0, CacheWakeupProbe::$woken);
    }

    public function testAllowedDateObjectsKeepMeaningfulBehavior(): void
    {
        $codec = new ObjectCacheValueCodec('secret');
        $decoded = $codec->decode($codec->encode(new \DateTimeImmutable('2026-10-01T12:00:00+00:00')));
        self::assertInstanceOf(\DateTimeImmutable::class, $decoded);
        self::assertSame('2026-10-02', $decoded->modify('+1 day')->format('Y-m-d'));
    }

    public function testConfiguredPluginClassesRequireAuthenticationBeforeTheirMagicMethodsRun(): void
    {
        CacheWakeupProbe::$woken = 0;
        $codec = new ObjectCacheValueCodec('secret', [CacheWakeupProbe::class]);
        $payload = $codec->encode(new CacheWakeupProbe());
        self::assertInstanceOf(CacheWakeupProbe::class, $codec->decode($payload));
        self::assertSame(1, CacheWakeupProbe::$woken);
        $tampered = str_replace('sympress-cache-v2:', 'sympress-cache-v2:bad', $payload);
        self::assertFalse($codec->decode($tampered));
        self::assertSame(1, CacheWakeupProbe::$woken);
        $unsigned = new ObjectCacheValueCodec(null, [CacheWakeupProbe::class]);
        self::assertFalse($unsigned->decode($unsigned->encode(new CacheWakeupProbe())));
        self::assertSame(1, CacheWakeupProbe::$woken);
        CacheWakeupProbe::$woken = 0;
    }
}

final class CacheWakeupProbe
{
    public static int $woken = 0;

    public function __wakeup(): void
    {
        self::$woken++;
    }
}
