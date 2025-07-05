<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Router\Contracts;

interface CacheInterface
{
    /**
     * Get a value from the cache.
     */
    public function get(string $key): mixed;

    /**
     * Set a value in the cache.
     */
    public function set(string $key, mixed $value, ?int $ttl = null): bool;

    /**
     * Check if a key exists in the cache.
     */
    public function has(string $key): bool;

    /**
     * Delete a key from the cache.
     */
    public function delete(string $key): bool;

    /**
     * Clear all cache entries.
     */
    public function clear(): bool;

    /**
     * Get multiple values from the cache.
     */
    public function getMultiple(array $keys): array;

    /**
     * Set multiple values in the cache.
     */
    public function setMultiple(array $values, ?int $ttl = null): bool;

    /**
     * Get cache statistics.
     */
    public function getStats(): array;
}
