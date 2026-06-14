<?php

declare(strict_types=1);

namespace SymPress\Framework\ObjectCache;

final class ObjectCacheValueCodec
{
    private const string LEGACY_SERIALIZED_PREFIX = 'sympress-cache-v1:';
    private const string SIGNED_SERIALIZED_PREFIX = 'sympress-cache-v2:';

    public function __construct(
        private readonly ?string $secret = null,
    ) {
    }

    public function encode(mixed $value): string
    {
        if (is_int($value) && $value >= 0) {
            return (string) $value;
        }

        $serialized = serialize($value);
        $secret = $this->normalizedSecret();

        if ($secret === null) {
            return self::LEGACY_SERIALIZED_PREFIX . $serialized;
        }

        return self::SIGNED_SERIALIZED_PREFIX
            . hash_hmac('sha256', $serialized, $secret)
            . ':'
            . base64_encode($serialized);
    }

    public function decode(mixed $payload): mixed
    {
        if (!is_string($payload)) {
            return false;
        }

        if ($payload !== '' && ctype_digit($payload)) {
            return (int) $payload;
        }

        if (str_starts_with($payload, self::SIGNED_SERIALIZED_PREFIX)) {
            return $this->decodeSignedPayload(substr($payload, strlen(self::SIGNED_SERIALIZED_PREFIX)));
        }

        if (str_starts_with($payload, self::LEGACY_SERIALIZED_PREFIX)) {
            return $this->unserializePayload(substr($payload, strlen(self::LEGACY_SERIALIZED_PREFIX)));
        }

        return $payload;
    }

    private function decodeSignedPayload(string $payload): mixed
    {
        $separator = strpos($payload, ':');

        if ($separator === false) {
            return false;
        }

        $signature = substr($payload, 0, $separator);
        $encoded = substr($payload, $separator + 1);
        $serialized = base64_decode($encoded, true);
        $secret = $this->normalizedSecret();

        if ($secret === null || !is_string($serialized)) {
            return false;
        }

        $expected = hash_hmac('sha256', $serialized, $secret);

        if (!hash_equals($expected, $signature)) {
            return false;
        }

        return $this->unserializePayload($serialized);
    }

    private function normalizedSecret(): ?string
    {
        if (!is_string($this->secret) || $this->secret === '') {
            return null;
        }

        return $this->secret;
    }

    private function unserializePayload(string $payload): mixed
    {
        $value = @unserialize($payload);

        if ($value === false && $payload !== 'b:0;') {
            return false;
        }

        return $value;
    }

}
