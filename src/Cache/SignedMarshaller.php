<?php

declare(strict_types=1);

namespace SymPress\Framework\Cache;

use Symfony\Component\Cache\Exception\CacheException;
use Symfony\Component\Cache\Marshaller\MarshallerInterface;

final readonly class SignedMarshaller implements MarshallerInterface
{
    private const string PREFIX = 'sympress-symfony-cache-v1:';
    private string $key;

    public function __construct(?string $secret, string $namespace, private MarshallerInterface $inner)
    {
        if ($secret === null || strlen($secret) < 32) {
            throw new CacheException('Persistent application caches require a strong application secret.');
        }
        $this->key = hash_hmac('sha256', 'sympress.symfony-cache:' . $namespace, $secret);
    }

    /** @param array<array-key, mixed> $values @param array<array-key>|null $failed @return array<array-key, string> */
    public function marshall(array $values, ?array &$failed): array
    {
        $encoded = $this->inner->marshall($values, $failed);
        foreach ($encoded as $id => $payload) {
            $encoded[$id] = self::PREFIX . hash_hmac('sha256', $payload, $this->key) . ':' . base64_encode($payload);
        }
        return $encoded;
    }

    public function unmarshall(string $value): mixed
    {
        if (!str_starts_with($value, self::PREFIX)) {
            throw new CacheException('Unsigned application cache payload.');
        }
        $payload = substr($value, strlen(self::PREFIX));
        $signature = substr($payload, 0, 64);
        $encoded = substr($payload, 65);
        $decoded = base64_decode($encoded, true);
        if (($payload[64] ?? null) !== ':' || !is_string($decoded) || !hash_equals(hash_hmac('sha256', $decoded, $this->key), $signature)) {
            throw new CacheException('Application cache authentication failed.');
        }
        return $this->inner->unmarshall($decoded);
    }
}
