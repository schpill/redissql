<?php

namespace Morbihanet\RedisSQL;

use Throwable;

class RedisSQLFileCache implements RedisSQLCacheInterface
{
    protected ?string $path = null;

    public function __construct(protected string $namespace = 'core')
    {
        $this->path = storage_path('app/cache/' . $namespace);
        $this->ensurePath();
    }

    public function set(string $key, mixed $value, int $ttl = 0): self
    {
        $file = $this->getFile($key);

        $data = serialize(value($value));

        if ($ttl) {
            $data = (time() + $ttl) . "%%$$" . $data;
        }

        file_put_contents($file, $data);

        return $this;
    }

    public function setex(string $key, int $ttl, mixed $value): self
    {
        return $this->set($key, $value, $ttl);
    }

    public function setFor(string $key, mixed $value, string|int $time = '1 DAY'): mixed
    {
        if (!$content = $this->get($key)) {
            $ttl = is_string($time) ? strtotime('+ ' . $time) : $time;
            $this->set($key, $content = value($value), $ttl);
        }

        return $content;
    }

    public function setnx(string $key, mixed $value): self
    {
        if (!$this->has($key)) {
            $this->set($key, $value);
        }

        return $this;
    }

    public function setnxex(string $key, mixed $value, int $ttl): self
    {
        if (!$this->has($key)) {
            $this->set($key, $value, $ttl);
        }

        return $this;
    }

    public function setnxFor(string $key, mixed $value, string|int $time): self
    {
        if (!$this->has($key)) {
            $this->setFor($key, $value, $time);
        }

        return $this;
    }

    public function seconds(string $key, mixed $value, int $second = 1): self
    {
        return $this->setFor($key, $value, $second . ' SECOND');
    }

    public function second(string $key, mixed $value): self
    {
        return $this->seconds($key, $value);
    }

    public function minutes(string $key, mixed $value, int $minute = 1): self
    {
        return $this->setFor($key, $value, $minute . ' MINUTE');
    }

    public function minute(string $key, mixed $value): self
    {
        return $this->minutes($key, $value);
    }

    public function hours(string $key, mixed $value, int $hour = 1): self
    {
        return $this->setFor($key, $value, $hour . ' HOUR');
    }

    public function hour(string $key, mixed $value): self
    {
        return $this->hours($key, $value);
    }

    public function days(string $key, mixed $value, int $day = 1): self
    {
        return $this->setFor($key, $value, $day . ' DAY');
    }

    public function day(string $key, mixed $value): self
    {
        return $this->days($key, $value);
    }

    public function weeks(string $key, mixed $value, int $week = 1): self
    {
        return $this->setFor($key, $value, $week . ' WEEK');
    }

    public function week(string $key, mixed $value): self
    {
        return $this->weeks($key, $value);
    }

    public function months(string $key, mixed $value, int $month = 1): self
    {
        return $this->setFor($key, $value, $month . ' MONTH');
    }

    public function month(string $key, mixed $value): self
    {
        return $this->months($key, $value);
    }

    public function years(string $key, mixed $value, int $year = 1): self
    {
        return $this->setFor($key, $value, $year . ' YEAR');
    }

    public function year(string $key, mixed $value): self
    {
        return $this->years($key, $value);
    }

    public function forever(string $key, mixed $value): self
    {
        return $this->setFor($key, $value, '10 YEARS');
    }

    public function getset(string $key, mixed $value): mixed
    {
        $old = $this->get($key);

        $this->set($key, $value);

        return $old;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if (!file_exists($file = $this->getFile($key))) {
            return value($default);
        }

        try {
            $data = file_get_contents($file);
        } catch (Throwable) {
            return value($default);
        }

        if (false === $data) {
            return value($default);
        }

        $data = explode("%%$$", $data, 2);

        if (count($data) === 2) {
            [$ttl, $data] = $data;

            if ($ttl < time()) {
                unlink($file);

                return value($default);
            }
        } else {
            $data = $data[0];
        }

        return unserialize($data);
    }

    public function exists(string $key): int
    {
        return $this->has($key) ? 1 : 0;
    }

    public function has(string $key): bool
    {
        if (!file_exists($file = $this->getFile($key))) {
            return false;
        }

        try {
            $data = file_get_contents($file);
        } catch (Throwable) {
            return false;
        }

        if (false === $data) {
            return false;
        }

        $data = explode("%%$$", $data, 2);

        if (count($data) === 2) {
            [$ttl, $data] = $data;

            if ($ttl < time()) {
                unlink($file);

                return false;
            }
        }

        return true;
    }

    public function forget(string $key): bool
    {
        if ($status = $this->has($key)) {
            unlink($this->getFile($key));
        }

        return $status && $this->hclear($key) && $this->sclear($key);
    }

    public function del(string $key): bool
    {
        return $this->forget($key);
    }

    public function incr(string $key, int $value = 1): int
    {
        $this->set($key, $new = $this->get($key, 0) + $value);

        return $new;
    }

    public function decr(string $key, int $value = 1): int
    {
        return $this->incr($key, $value * -1);
    }

    protected function getFile(string $key): string
    {
        $file = $this->path . '/' . $this->unsha1(sha1($key)) . '.cache';
        $parts = explode('/', $file);
        array_pop($parts);
        $dir = implode('/', $parts);

        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        return $file;
    }

    protected function ensurePath(?string $path = null)
    {
        $path ??= $this->path;

        if (!is_dir($path)) {
            mkdir($path, 0777, true);
        }
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

    public function __get(string $key): mixed
    {
        return $this->get($key);
    }

    public function __set(string $key, mixed $value): void
    {
        $this->set($key, $value);
    }

    public function __isset(string $key): bool
    {
        return $this->has($key);
    }

    public function __unset(string $key): void
    {
        $this->forget($key);
    }

    protected function unsha1(string $sha1): string
    {
        return substr($sha1, 0, 2) .
            '/' . substr($sha1, 2, 2) .
            '/' . substr($sha1, 4, 2) .
            '/' . substr($sha1, 6, 2) .
            '/' . substr($sha1, 8)
        ;
    }

    public function hset(string $key, string $field, mixed $value): self
    {
        if (file_exists($file = $this->getFile($key))) {
            $data = unserialize(file_get_contents($file));
        } else {
            $data = [];
        }

        $data[$field] = $value;

        file_put_contents($file, serialize($data));

        return $this;
    }

    public function hget(string $key, string $field, mixed $default = null): mixed
    {
        if (!file_exists($file = $this->getFile($key))) {
            return value($default);
        }

        $data = unserialize(file_get_contents($file));

        return $data[$field] ?? value($default);
    }

    public function hhas(string $key, string $field): bool
    {
        if (!file_exists($file = $this->getFile($key))) {
            return false;
        }

        $data = unserialize(file_get_contents($file));

        return isset($data[$field]);
    }

    public function hexists(string $key, string $field): bool
    {
        return $this->hhas($key, $field);
    }

    public function hdel(string $key, ?string $field = null): bool
    {
        if (!$field) {
            return $this->hclear($key);
        }

        if (!file_exists($file = $this->getFile($key))) {
            return false;
        }

        $data = unserialize(file_get_contents($file));

        unset($data[$field]);

        file_put_contents($file, serialize($data));

        return true;
    }

    public function hgetall(string $key): array
    {
        if (!file_exists($file = $this->getFile($key))) {
            return [];
        }

        return unserialize(file_get_contents($file));
    }

    public function hincr(string $key, string $field, int $value = 1): int
    {
        $old = $this->hget($key, $field, 0);

        $new = $old + $value;

        $this->hset($key, $field, $new);

        return $new;
    }

    public function hdecr(string $key, string $field, int $value = 1): int
    {
        return $this->hincr($key, $field, $value * -1);
    }

    public function hkeys(string $key, string $pattern = '*'): array
    {
        if (!file_exists($file = $this->getFile($key))) {
            return [];
        }

        $data = unserialize(file_get_contents($file));

        $keys = array_keys($data);

        if ($pattern === '*') {
            return $keys;
        }

        $pattern = str_replace('*', '.*', $pattern);

        return preg_grep('/^' . $pattern . '$/', $keys);
    }

    public function hvals(string $key): array
    {
        if (!file_exists($file = $this->getFile($key))) {
            return [];
        }

        $data = unserialize(file_get_contents($file));

        return array_values($data);
    }

    public function hlen(string $key): int
    {
        return count($this->hkeys($key));
    }

    public function hscan(string $key, int $cursor = 0, string $pattern = '*', int $count = 10): array
    {
        $keys = $this->hkeys($key, $pattern);

        $total = count($keys);

        $keys = array_slice($keys, $cursor, $count);

        $cursor += $count;

        return [$cursor, $keys, $total];
    }

    public function hclear(string $key): bool
    {
        if (!file_exists($file = $this->getFile($key))) {
            return false;
        }

        unlink($file);

        return !file_exists($file);
    }

    public function hdelall(string $key): bool
    {
        return $this->hclear($key);
    }

    public function sadd(string $key, mixed $value): self
    {
        if (file_exists($file = $this->getFile($key))) {
            $data = unserialize(file_get_contents($file));
        } else {
            $data = [];
        }

        $data[] = $value;

        file_put_contents($file, serialize($data));

        return $this;
    }

    public function srem(string $key, mixed $value): bool
    {
        if (!file_exists($file = $this->getFile($key))) {
            return false;
        }

        $data = unserialize(file_get_contents($file));

        $data = array_filter($data, function ($item) use ($value) {
            return $item !== $value;
        });

        file_put_contents($file, serialize($data));

        return true;
    }

    public function sismember(string $key, mixed $value): bool
    {
        if (!file_exists($file = $this->getFile($key))) {
            return false;
        }

        $data = unserialize(file_get_contents($file));

        return in_array($value, $data);
    }

    public function smembers(string $key): array
    {
        if (!file_exists($file = $this->getFile($key))) {
            return [];
        }

        return unserialize(file_get_contents($file));
    }

    public function sclear(string $key): bool
    {
        if (!file_exists($file = $this->getFile($key))) {
            return false;
        }

        unlink($file);

        return !file_exists($file);
    }

    public function sdelall(string $key): bool
    {
        return $this->sclear($key);
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function setPath(string $path): self
    {
        $this->path = $path;

        return $this;
    }

    public function getNamespace(): string
    {
        return $this->namespace;
    }

    public function setNamespace(string $namespace): self
    {
        $this->namespace = $namespace;

        return $this;
    }

    public function multi(): self
    {
        $bkpNamespace = $this->namespace . '/bkp';
        $bkpPath = $this->path . '/bkp';

        $this->copy($bkpPath);

        $this->setPath($bkpPath);
        $this->setNamespace($bkpNamespace);

        return $this;
    }

    public function exec(): self
    {
        $basePath = str_replace('/bkp', '', $this->path);
        $baseNamespace = str_replace('/bkp', '', $this->namespace);

        $this->destroyDirectory($basePath);
        $this->ensurePath($basePath);
        $this->copy($basePath);

        $this->setPath($basePath);
        $this->setNamespace($baseNamespace);

        return $this;
    }

    public function discard(): self
    {
        $basePath = str_replace('/bkp', '', $this->path);
        $baseNamespace = str_replace('/bkp', '', $this->namespace);

        $this->destroyDirectory();

        $this->setPath($basePath);
        $this->setNamespace($baseNamespace);

        return $this;
    }

    public function beginTransaction(): self
    {
        return $this->multi();
    }

    public function commit(): self
    {
        return $this->exec();
    }

    public function rollback(): self
    {
        return $this->discard();
    }

    public function transaction(callable $callback): mixed
    {
        $this->beginTransaction();

        try {
            $result = $callback($this);

            $this->commit();

            return $result;
        } catch (Throwable $th) {
            $this->rollback();

            throw $th;
        }
    }

    public function destroyDirectory(?string $path = null): bool
    {
        return app('files')->deleteDirectory($path ?? $this->path);
    }

    public function copy(string $bkpPath, ?string $path = null): bool
    {
        return app('files')->copyDirectory($path ?? $this->path, $bkpPath);
    }

    public function intersect(string $key1, string $key2): array
    {
        $data1 = $this->smembers($key1);
        $data2 = $this->smembers($key2);

        return array_intersect($data1, $data2);
    }

    public function union(string $key1, string $key2): array
    {
        $data1 = $this->smembers($key1);
        $data2 = $this->smembers($key2);

        return array_unique(array_merge($data1, $data2));
    }

    public function diff(string $key1, string $key2): array
    {
        $data1 = $this->smembers($key1);
        $data2 = $this->smembers($key2);

        return array_diff($data1, $data2);
    }

    public function hmset(string $key, array $data): self
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

        return true;
    }

    public function remember(string $key, int $ttl, callable $callback): mixed
    {
        if (!$content = $this->get($key)) {
            $this->set($key, $content = $callback($this), $ttl);
        }

        return $content;
    }

    public function flush(): bool
    {
        return $this->destroyDirectory();
    }

    public function expire(string $key, int $ttl): bool
    {
        if ($this->has($key)) {
            $this->set($key, $this->get($key), $ttl);

            return true;
        }

        return false;
    }

    public function expiretime(string $key): int
    {
        if ($this->has($key)) {
            $data = file_get_contents($this->getFile($key));

            if (false === $data) {
                return 0;
            }

            $data = explode("%%$$", $data, 2);

            if (count($data) === 2) {
                return current($data);
            }

        }

        return 0;
    }

    public function keys(): array
    {
        $files = glob($this->path . '/*/*.cache');

        return array_map(function ($file) {
            return str_replace([$this->path, '/', '.cache'], '', $file);
        }, $files);
    }

    public function count(): int
    {
        return count($this->keys());
    }
}
