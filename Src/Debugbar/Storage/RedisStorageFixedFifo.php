<?php

namespace KonstantinBudylov\EloquentSpanner\Debugbar\Storage;

use DebugBar\Storage\StorageInterface;
use Illuminate\Support\Str;

/**
 * Stores collected data into Redis
 *
 * Implements FIFO list with automatic deletion of oldest records
 */
class RedisStorageFixedFifo implements StorageInterface
{
    private mixed $redis;

    private string $hash;

    /**
     * @var int items to keep in fifo memory
     */
    private int $fifoMaxLength;

    /**
     * @param  mixed $redis Redis Client
     * @param  int $fifoMaxLength
     * @param  string $hash
     */
    public function __construct(mixed $redis = null, ?int $fifoMaxLength = null, string $hash = 'phpdebugbar')
    {
        if (!$redis) {
            $redis = app('redis')->connection()->client();
        }
        $this->redis = $redis;
        $this->hash = $hash;
        $this->fifoMaxLength = $fifoMaxLength ?: config('debugbar.storage.fifo_length', 1000);
    }

    /**
     * Saves collected data
     *
     * @param string $id
     * @param string|array<mixed> $data
     */
    public function save($id, $data): void
    {
        $uri = $data['__meta']['uri'] ?? '';
        if ($this->inExceptArray($uri)) {
            return;
        }
        if (is_array($data) && isset($data['__meta'])) {
            $this->redis->hset("{$this->hash}:meta", $id, serialize($data['__meta']));
            unset($data['__meta']);
        }
        $this->redis->hset("{$this->hash}:data", $id, serialize($data));
        $listLen = $this->redis->lpush("{$this->hash}:list", $id);

        // remove oldest items
        if ($listLen > $this->fifoMaxLength) {
            $id = $this->redis->rpop("{$this->hash}:list");
            $this->redis->hdel("{$this->hash}:meta", $id);
            $this->redis->hdel("{$this->hash}:data", $id);
        }
    }

    /**
     * Determine if the request has a URI that should be ignored.
     */
    protected function inExceptArray(string $uri): bool
    {
        $except = config('debugbar.except_save') ?: [];
        foreach ($except as $except) {
            if (Str::is($except, $uri)) {
                return true;
            }
        }

        return false;
    }

    /**
     * {@inheritdoc}
     *
     * @return array<mixed>
     */
    public function get($id): array
    {
        $data = $this->redis->hget("{$this->hash}:data", $id);
        if (!$data) {
            return [
                '__meta' => [
                    'id' => $id,
                    'method' => '',
                    'uri' => '',
                    'utime' => '',
                ],
            ];
        }
        return array_merge(
            unserialize($data),
            ['__meta' => unserialize($this->redis->hget("{$this->hash}:meta", $id))]
        );
    }

    /**
     * {@inheritdoc}
     *
     * @param array<mixed> $filters
     *
     * @return array<mixed>
     */
    public function find(array $filters = [], mixed $max = 20, mixed $offset = 0): array
    {
        $results = [];
        $cursor = "0";
        do {
            $list = $this->redis->hscan("{$this->hash}:meta", $cursor);
            if (isset($list[0]) && isset($list[1])) {
                list($cursor, $data) = $list;
            } else {
                // redis extension changes cursor in memory
                $data = $list;
            }
            foreach ($data as $meta) {
                if ($meta = unserialize($meta)) {
                    if ($this->filter($meta, $filters)) {
                        $results[] = $meta;
                    }
                }
            }
        } while ($cursor);

        usort($results, function ($left, $right) {
            return $right['utime'] <=>  $left['utime'];
        });

        return array_slice($results, $offset, $max);
    }

    /**
     * Filter the metadata for matches.
     *
     * @param array<mixed> $meta
     * @param array<mixed> $filters
     */
    protected function filter(array $meta, array $filters): bool
    {
        foreach ($filters as $key => $value) {
            if (!isset($meta[$key]) || fnmatch($value, $meta[$key]) === false) {
                return false;
            }
        }
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function clear(): void
    {
        $this->redis->del("{$this->hash}:list");
        $this->redis->del("{$this->hash}:meta");
        $this->redis->del("{$this->hash}:data");
    }
}
