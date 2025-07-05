<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Router;

use HighPerApp\HighPer\Router\Contracts\CacheInterface;

/**
 * Ring Buffer Cache - O(1) Router Cache Eviction.
 *
 * Implements ring buffer with O(1) operations for route caching.
 * Optimized for high-frequency route resolution without GC pressure.
 *
 * Total: ~35 LOC as per project plan
 */
class RingBufferCache implements CacheInterface
{
    private array $buffer;

    private array $keys;

    private int $head = 0;

    private int $size;

    private array $stats = ['hits' => 0, 'misses' => 0, 'evictions' => 0];

    public function __construct(int $size = 1024)
    {
        $this->size = $size;
        $this->buffer = array_fill(0, $size, null);
        $this->keys = array_fill(0, $size, null);
    }

    public function get(string $key): mixed
    {
        $hash = $this->hash($key);
        if ($this->keys[$hash] === $key && $this->buffer[$hash] !== null) {
            $this->stats['hits']++;

            return $this->buffer[$hash];
        }
        $this->stats['misses']++;

        return null;
    }

    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        $hash = $this->hash($key);
        if ($this->keys[$hash] !== null && $this->keys[$hash] !== $key) {
            $this->stats['evictions']++;
        }
        $this->keys[$hash] = $key;
        $this->buffer[$hash] = $value;
        $this->head = ($this->head + 1) % $this->size;

        return true;
    }

    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    public function delete(string $key): bool
    {
        $hash = $this->hash($key);
        $this->keys[$hash] = null;
        $this->buffer[$hash] = null;

        return true;
    }

    public function clear(): bool
    {
        $this->buffer = array_fill(0, $this->size, null);
        $this->keys = array_fill(0, $this->size, null);

        return true;
    }

    public function getStats(): array
    {
        return array_merge($this->stats, ['size' => $this->size, 'head' => $this->head]);
    }

    public function getMultiple(array $keys): array
    {
        return array_map([$this, 'get'], $keys);
    }

    public function setMultiple(array $values, ?int $ttl = null): bool
    {
        foreach ($values as $k => $v) {
            $this->set($k, $v, $ttl);
        }

return true;
    }

    private function hash(string $key): int
    {
        return crc32($key) % $this->size;
    }
}
