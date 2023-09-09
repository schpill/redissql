<?php
namespace Morbihanet\RedisSQL;

use ArrayAccess;
use Countable;

interface RedisSQLCacheInterface extends ArrayAccess, Countable
{
    public function set(string $key, mixed $value, int $ttl = 0): \Morbihanet\RedisSQL\RedisSQLCacheInterface;

    public function setex(string $key, int $ttl, mixed $value): \Morbihanet\RedisSQL\RedisSQLCacheInterface;

    public function setFor(string $key, mixed $value, string|int $time = '1 DAY'): mixed;

    public function get(string $key, mixed $default = null): mixed;

    public function exists(string $key): int;

    public function has(string $key): bool;

    public function forget(string $key): bool;

    public function del(string $key): bool;

    public function incr(string $key, int $value = 1): int;

    public function decr(string $key, int $value = 1): int;

    public function offsetExists($offset);

    public function offsetGet($offset);

    public function offsetSet($offset, $value);

    public function offsetUnset($offset);

    public function hset(string $key, string $field, mixed $value): \Morbihanet\RedisSQL\RedisSQLCacheInterface;

    public function hget(string $key, string $field, mixed $default = null): mixed;

    public function hhas(string $key, string $field): bool;

    public function hexists(string $key, string $field): bool;

    public function hdel(string $key, ?string $field = null): bool;

    public function hgetall(string $key): array;

    public function hincr(string $key, string $field, int $value = 1): int;

    public function hdecr(string $key, string $field, int $value = 1): int;

    public function hkeys(string $key, string $pattern = '*'): array;

    public function hvals(string $key): array;

    public function hlen(string $key): int;

    public function hscan(string $key, int $cursor = 0, string $pattern = '*', int $count = 10): array;

    public function hclear(string $key): bool;

    public function hdelall(string $key): bool;

    public function sadd(string $key, mixed $value): \Morbihanet\RedisSQL\RedisSQLCacheInterface;

    public function srem(string $key, mixed $value): bool;

    public function sismember(string $key, mixed $value): bool;

    public function smembers(string $key): array;

    public function sclear(string $key): bool;

    public function sdelall(string $key): bool;

    public function getPath(): string;

    public function setPath(string $path): \Morbihanet\RedisSQL\RedisSQLCacheInterface;

    public function getNamespace(): string;

    public function setNamespace(string $namespace): \Morbihanet\RedisSQL\RedisSQLCacheInterface;

    public function multi(): \Morbihanet\RedisSQL\RedisSQLCacheInterface;

    public function exec(): \Morbihanet\RedisSQL\RedisSQLCacheInterface;

    public function discard(): \Morbihanet\RedisSQL\RedisSQLCacheInterface;

    public function beginTransaction(): \Morbihanet\RedisSQL\RedisSQLCacheInterface;

    public function commit(): \Morbihanet\RedisSQL\RedisSQLCacheInterface;

    public function rollback(): \Morbihanet\RedisSQL\RedisSQLCacheInterface;

    public function transaction(callable $callback): mixed;

    public function destroyDirectory(?string $path = null): bool;

    public function copy(string $bkpPath, ?string $path = null): bool;

    public function intersect(string $key1, string $key2): array;

    public function union(string $key1, string $key2): array;

    public function diff(string $key1, string $key2): array;

    public function hmset(string $key, array $data): \Morbihanet\RedisSQL\RedisSQLCacheInterface;

    public function hmget(string $key, array $fields): array;

    public function hmdel(string $key, array $fields): bool;

    public function remember(string $key, int $ttl, callable $callback): mixed;

    public function flush(): bool;

    public function expire(string $key, int $ttl): bool;

    public function expiretime(string $key): int;
}
