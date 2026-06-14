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
}
