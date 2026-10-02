<?php

declare(strict_types=1);

namespace SymPress\Framework\ObjectCache;

use Symfony\Component\Cache\Marshaller\MarshallerInterface;

final readonly class ObjectCacheMarshaller implements MarshallerInterface
{
    public function __construct(private ObjectCacheValueCodec $codec)
    {
    }

    /**
     * @param array<array-key, mixed> $values
     * @param-out list<array-key> $failed
     * @return array<array-key, string>
     */
    public function marshall(array $values, ?array &$failed): array
    {
        $failed = [];
        $encoded = [];
        foreach ($values as $key => $value) {
            try {
                // The envelope authenticates integers too and distinguishes cached false
                // from a rejected payload without executing application magic methods.
                $encoded[$key] = $this->codec->encode([$value]);
            } catch (\Throwable) {
                $failed[] = $key;
            }
        }

        return $encoded;
    }

    public function unmarshall(string $value): mixed
    {
        if (!str_starts_with($value, 'sympress-cache-v2:')) {
            throw new \DomainException('Unsigned object-cache payload.');
        }
        $decoded = $this->codec->decode($value);
        if (!is_array($decoded) || array_keys($decoded) !== [0]) {
            throw new \DomainException('Invalid authenticated object-cache payload.');
        }

        return $decoded[0];
    }
}
