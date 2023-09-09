<?php
namespace Morbihanet\RedisSQL;

class RedisSQLMemoryCache implements RedisSQLCacheInterface
{
    protected static array $_datas = [];

    public function __construct(protected string $path = 'core', protected string $namespace = 'core')
    {
    }

    public function count(): int
    {
        return count(static::$_datas[$this->path][$this->namespace] ?? []);
    }

    public function set(string $key, mixed $value, int $ttl = 0): self
    {
        $k = $this->getKey($key);

        array_set(static::$_datas, $k, $value);

        return $this;
    }

    public function setex(string $key, int $ttl, mixed $value): self
    {
        return $this->set($key, $value, $ttl);
    }

    public function setFor(string $key, mixed $value, int|string $time = '1 DAY'): mixed
    {
        if (!$this->has($key)) {
            $this->setex($key, is_string($time) ? strtotime($time) - time() : $time, $v = value($value));
        }

        return $v ?? $this->get($key);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return array_get(static::$_datas, $this->getKey($key), value($default));
    }

    public function exists(string $key): int
    {
        return array_has(static::$_datas, $this->getKey($key)) ? 1 : 0;
    }

    public function has(string $key): bool
    {
        return array_has(static::$_datas, $this->getKey($key));
    }

    public function forget(string $key): bool
    {
        array_forget(static::$_datas, $this->getKey($key));

        return !$this->has($key);
    }

    public function del(string $key): bool
    {
        return $this->forget($key);
    }

    public function incr(string $key, int $value = 1): int
    {
        $v = array_get(static::$_datas, $k = $this->getKey($key), 0) + $value;

        array_set(static::$_datas, $k, $v);

        return $v;
    }

    public function decr(string $key, int $value = 1): int
    {
        return $this->incr($key, $value * -1);
    }

    public function offsetExists($offset)
    {
        return $this->has($offset);
    }

    public function offsetGet($offset)
    {
        return $this->get($offset);
    }

    public function offsetSet($offset, $value)
    {
        $this->set($offset, $value);
    }

    public function offsetUnset($offset)
    {
        $this->forget($offset);
    }

    public function hset(string $key, string $field, mixed $value): self
    {
        array_set(static::$_datas, $this->getKey($key . '.' . $field), value($value));

        return $this;
    }

    public function hget(string $key, string $field, mixed $default = null): mixed
    {
        return array_get(static::$_datas, $this->getKey($key . '.' . $field), value($default));
    }

    public function hhas(string $key, string $field): bool
    {
        return array_has(static::$_datas, $this->getKey($key . '.' . $field));
    }

    public function hexists(string $key, string $field): bool
    {
        return $this->hhas($key, $field);
    }

    public function hdel(string $key, ?string $field = null): bool
    {
        if (null === $field) {
            array_forget(static::$_datas, $this->getKey($key));
        } else {
            array_forget(static::$_datas, $this->getKey($key . '.' . $field));
        }

        return !$this->hhas($key, $field);
    }

    public function hgetall(string $key): array
    {
        return array_get(static::$_datas, $this->getKey($key), []);
    }

    public function hincr(string $key, string $field, int $value = 1): int
    {
        $v = array_get(static::$_datas, $k = $this->getKey($key . '.' . $field), 0) + $value;

        array_set(static::$_datas, $k, $v);

        return $v;
    }

    public function hdecr(string $key, string $field, int $value = 1): int
    {
        return $this->hincr($key, $field, $value * -1);
    }

    public function hkeys(string $key, string $pattern = '*'): array
    {
        return array_keys($this->hgetall($key));
    }

    public function hvals(string $key): array
    {
        return array_values($this->hgetall($key));
    }

    public function hlen(string $key): int
    {
        return count($this->hgetall($key));
    }

    public function hscan(string $key, int $cursor = 0, string $pattern = '*', int $count = 10): array
    {
        $data = $this->hgetall($key);

        $keys = array_keys($data);

        $values = array_values($data);

        $total = count($keys);

        $keys = array_slice($keys, $cursor, $count);

        $values = array_slice($values, $cursor, $count);

        $cursor = $cursor + $count;

        return [$cursor, array_combine($keys, $values), $total];
    }

    public function hclear(string $key): bool
    {
        array_forget(static::$_datas, $this->getKey($key));

        return !$this->hhas($key);
    }

    public function hdelall(string $key): bool
    {
        return $this->hclear($key);
    }

    public function sadd(string $key, mixed $value): self
    {
        $k = $this->getKey($key);

        $data = array_get(static::$_datas, $k, []);

        if (!in_array($value, $data)) {
            $data[] = $value;
        }

        array_set(static::$_datas, $k, $data);

        return $this;
    }

    public function srem(string $key, mixed $value): bool
    {
        $k = $this->getKey($key);

        $data = array_get(static::$_datas, $k, []);

        if (in_array($value, $data)) {
            $data = array_diff($data, [$value]);
        }

        array_set(static::$_datas, $k, $data);

        return !$this->sismember($key, $value);
    }

    public function sismember(string $key, mixed $value): bool
    {
        return in_array($value, $this->smembers($key));
    }

    public function smembers(string $key): array
    {
        return array_get(static::$_datas, $this->getKey($key), []);
    }

    public function sclear(string $key): bool
    {
        array_forget(static::$_datas, $this->getKey($key));

        return !$this->sismember($key);
    }

    public function sdelall(string $key): bool
    {
        return $this->sclear($key);
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function setPath(string $path): \App\Services\RedisSQLCacheInterface
    {
        $this->path = $path;

        return $this;
    }

    public function getNamespace(): string
    {
        return $this->namespace;
    }

    public function setNamespace(string $namespace): \App\Services\RedisSQLCacheInterface
    {
        $this->namespace = $namespace;

        return $this;
    }

    public function multi(): self
    {
        return $this;
    }

    public function exec(): self
    {
        return $this;
    }

    public function discard(): self
    {
        return $this;
    }

    public function beginTransaction(): self
    {
        return $this;
    }

    public function commit(): self
    {
        return $this;
    }

    public function rollback(): self
    {

    }

    public function transaction(callable $callback): mixed
    {
        return $callback($this);
    }

    public function destroyDirectory(?string $path = null): bool
    {
        unset(static::$_datas[$this->path][$this->namespace]);

        return true;
    }

    public function copy(string $bkpPath, ?string $path = null): bool
    {
        return $this->flush();
    }

    public function intersect(string $key1, string $key2): array
    {
        $values1 = $this->smembers($key1);
        $values2 = $this->smembers($key2);

        return array_intersect($values1, $values2);
    }

    public function union(string $key1, string $key2): array
    {
        $values1 = $this->smembers($key1);
        $values2 = $this->smembers($key2);

        return array_unique(array_merge($values1, $values2));
    }

    public function diff(string $key1, string $key2): array
    {
        $values1 = $this->smembers($key1);
        $values2 = $this->smembers($key2);

        return array_diff($values1, $values2);
    }

    public function hmset(string $key, array $data): \App\Services\RedisSQLCacheInterface
    {
        foreach ($data as $field => $value) {
            $this->hset($key, $field, $value);
        }

        return $this;
    }

    public function hmget(string $key, array $fields): array
    {
        $data = [];

        foreach ($fields as $field) {
            $data[$field] = $this->hget($key, $field);
        }

        return $data;
    }

    public function hmdel(string $key, array $fields): bool
    {
        foreach ($fields as $field) {
            $this->hdel($key, $field);
        }

        return !$this->hhas($key);
    }

    public function remember(string $key, int $ttl, callable $callback): mixed
    {
        if ($this->has($key)) {
            return $this->get($key);
        }

        $this->setex($key, $ttl, $value = $callback());

        return $value;
    }

    public function flush(): bool
    {
        unset(static::$_datas[$this->path][$this->namespace]);

        return true;
    }

    public function expire(string $key, int $ttl): bool
    {
        return true;
    }

    public function expiretime(string $key): int
    {
        return 0;
    }

    protected function getKey(string $key): string
    {
        return $this->path . '.'  .$this->namespace . '.' . $key;
    }
}
