<?php

declare(strict_types=1);

namespace SymPress\Framework\ObjectCache;

final class ObjectCacheValueCodec
{
    private const string LEGACY_SERIALIZED_PREFIX = 'sympress-cache-v1:';
    private const string SIGNED_SERIALIZED_PREFIX = 'sympress-cache-v2:';

    /** @param list<string> $allowedClasses */
    public function __construct(
        private readonly ?string $secret = null,
        private readonly array $allowedClasses = [],
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
            return $this->normalizedSecret() === null
                ? $this->unserializePayload(substr($payload, strlen(self::LEGACY_SERIALIZED_PREFIX)))
                : false;
        }

        return $this->normalizedSecret() === null ? $payload : false;
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
        // Additional application classes require explicit trusted configuration and
        // a valid signed payload. Unsigned legacy payloads keep the core allowlist.
        $allowed = ['stdClass', 'WP_Post', 'WP_Term', 'WP_Comment', 'WP_User', 'WP_Error', 'WP_Site', 'WP_Network', 'DateTime', 'DateTimeImmutable', 'DateTimeZone'];
        if ($this->normalizedSecret() !== null) {
            $allowed = array_values(array_unique([...$allowed, ...$this->allowedClasses]));
        }
        try {
            $value = @unserialize($payload, ['allowed_classes' => $allowed]);
        } catch (\Throwable) {
            return false;
        }

        if ($this->containsIncompleteObject($value)) {
            return false;
        }

        if ($value === false && $payload !== 'b:0;') {
            return false;
        }

        return $value;
    }

    private function containsIncompleteObject(mixed $value, int $depth = 0): bool
    {
        if ($value instanceof \__PHP_Incomplete_Class || $depth > 64) {
            return true;
        }

        if (is_array($value) || is_object($value)) {
            foreach ((array) $value as $item) {
                if ($this->containsIncompleteObject($item, $depth + 1)) {
                    return true;
                }
            }
        }

        return false;
    }
}
