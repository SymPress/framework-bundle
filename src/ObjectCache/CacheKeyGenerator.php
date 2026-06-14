<?php

declare(strict_types=1);

namespace SymPress\Framework\ObjectCache;

final class CacheKeyGenerator
{
    /**
     * @var array<string, true>
     */
    private array $globalGroups = [];

    public function __construct(
        private int $blogId = 1,
    ) {
    }

    /**
     * @param string|array<int, string> $groups
     * @return array<string, true>
     */
    public function addGlobalGroups(string|array $groups): array
    {
        foreach ((array) $groups as $group) {
            $this->globalGroups[(string) $group] = true;
        }

        return $this->globalGroups;
    }

    public function switchToBlog(int $blogId): bool
    {
        $this->blogId = max(1, $blogId);

        return true;
    }

    public function create(string $key, string $group = 'default'): string
    {
        return $this->groupPrefix($group) . hash('sha256', $key);
    }

    public function groupPrefix(string $group = 'default'): string
    {
        $group = $group === '' ? 'default' : $group;
        $scope = isset($this->globalGroups[$group]) ? 'global' : (string) $this->blogId;

        return sprintf('g.%s.b.%s.k.', $this->normalizeGroup($group), $scope);
    }

    private function normalizeGroup(string $group): string
    {
        $normalized = preg_replace('/[^A-Za-z0-9_.-]/', '_', $group) ?: 'default';
        $normalized = trim($normalized, '._-') ?: 'default';

        return substr($normalized, 0, 40) . '.' . substr(hash('xxh128', $group), 0, 8);
    }
}
