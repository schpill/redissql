<?php

namespace Morbihanet\RedisSQL;

use ArrayAccess;
use Closure;
use Countable;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Support\Traits\Macroable;

class RedisSQLStore implements Store, ArrayAccess, Countable
{
    protected ?RedisSQL $store = null;
    public static bool $cleaned = false;

    use Macroable;

    public function __construct(protected string $ns = 'core')
    {
        $this->store = RedisSQL::forTable('cachestore');

        if ((time() - app('redis')->get('cachestore.cleaned_at') ?? 0) > 3600) {
            static::clean();
        }

        static::$cleaned = true;
    }

    public static function clean(): void
    {
        if (static::$cleaned) {
            return;
        }

        RedisSQL::forTable('cachestore')->where('ttl', '<', time())->delete();

        static::$cleaned = true;
        app('redis')->set('cachestore.cleaned_at', time());
    }

    public static function make(?string $namespace = null): self
    {
        return new static($namespace ?? str_replace('\\', '', Str::snake(class_basename(static::class))));
    }

    public function get($key)
    {
        if ($row = $this->store->where('ns', $this->ns)->where('key', $key)->first()) {
            $ttl = $row->ttl;

            if ($ttl && $ttl < time()) {
                $this->forget($key);

                return null;
            }

            return value($row->value);
        }

        return null;
    }

    public function getOr(string $key, mixed $otherwise = null): mixed
    {
        return $this->get($key) ?? value($otherwise);
    }

    public function pull(string $key, mixed $default = null): mixed
    {
        return tap($this->getOr($key, $default), fn () => $this->forget($key));
    }

    public function getOrCreate(string $key, callable $closure, int $seconds = 0): mixed
    {
        if ($value = $this->get($key)) {
            return $value;
        }

        $this->put($key, $value = $closure($this), $seconds);

        return $value;
    }

    public function getOrSet(string $key, callable $closure, int $seconds = 0): mixed
    {
        return $this->getOrCreate($key, $closure, $seconds);
    }

    public function getOrSetForever(string $key, callable $closure): mixed
    {
        return $this->getOrCreate($key, $closure, strtotime('+10 years'));
    }

    public function many(array $keys)
    {
        foreach ($keys as $key) {
            $results[$key] = $this->get($key);
        }

        return $results;
    }

    public function keys(string $pattern = '*'): array
    {
        static::clean();

        return $this->store->where('ns', $this->ns)->where('key', 'like', $pattern)->pluck('key')->toArray();
    }

    public function all(): array
    {
        static::clean();

        return $this->store->where('ns', $this->ns)->pluck('value', 'key')->toArray();
    }

    public function toArray(): array
    {
        return $this->all();
    }

    public function toCollection(): Collection
    {
        return collect($this->all());
    }

    public function toJson(int $option = JSON_PRETTY_PRINT): string
    {
        return json_encode($this->all(), $option);
    }

    public function setFor(string $key, mixed $value, mixed $time = '1 DAY')
    {
        if (!$val = $this->get($key)) {
            $max = is_string($time) ? strtotime('+ ' . $time) : $time;
            $seconds = $max - time();
            $this->put($key, $value, $seconds);
        }

        return value($val ?? $value);
    }

    public function add(string $key, mixed $value, int $seconds = 0): self
    {
        if (!$this->has($key)) {
            $this->put($key, $value, $seconds);
        }

        return $this;
    }

    public function has(string $key): bool
    {
        return $this->offsetExists($key);
    }

    public function missing(string $key): bool
    {
        return !$this->has($key);
    }

    public function until(string $name, Closure $closure, int $timestamp, ...$args)
    {
        $db     = new static('untils');
        $row    = $db->getOr($name, []);

        $when    = $row['when'] ?? null;
        $value   = $row['value'] ?? '__dummy__';

        if (null !== $when && '__dummy__' !== $value) {
            $when = (int) $when;

            if ($timestamp === $when) {
                return $value;
            }
        }

        $data = $closure(...$args);

        $row['when'] = $timestamp;
        $row['value'] = $data;
        $db->put($name, $row, strtotime('+10 years'));

        return $data;
    }

    public function delete(string $key, ?Closure $after = null): bool
    {
        if ($status = $this->forget($key)) {
            if ($after instanceof Closure) {
                $after->bindTo($this, $this);
                $after($key);
            }
        }

        return $status;
    }

    public function isEmpty()
    {
        return 0 === $this->count();
    }

    public function each()
    {
        foreach ($this->toArray() as $key => $value) {
            yield $key => $value;
        }
    }

    public function put($key, $value, $seconds)
    {
        $this->store->updateOrCreate([
            'ns' => $this->ns,
            'key' => $key,
        ], [
            'value' => $value,
            'ttl' => $seconds ? time() + $seconds : strtotime('+10 years'),
        ]);

        return true;
    }

    public function set(string $key, mixed $value, int $seconds = 0): self
    {
        $this->put($key, $value, $seconds);

        return $this;
    }

    public function putMany(array $values, $seconds)
    {
        foreach ($values as $key => $value) {
            $this->put($key, $value, $seconds);
        }

        return true;
    }

    public function setMany(array $values, int $seconds = 0): self
    {
        $this->putMany($values, $seconds);

        return $this;
    }

    public function increment($key, $value = 1)
    {
        $old = $this->get($key) ?? 0;

        $this->put($key, $new = ($old + $value), 0);

        return $new;
    }

    public function decrement($key, $value = 1)
    {
        return $this->increment($key, $value * -1);
    }

    public function forever($key, $value)
    {
        return $this->put($key, $value, strtotime('+10 years'));
    }

    public function forget($key)
    {
        if ($status = $this->has($key)) {
            $this->store->where('ns', $this->ns)->where('key', $key)->delete();
        }

        return $status;
    }

    public function flush()
    {
        $this->store->where('ns', $this->ns)->delete();

        return $this->store->where('ns', $this->ns)->isEmpty();
    }

    public function getPrefix()
    {
        return $this->ns;
    }

    public function offsetExists(mixed $offset): bool
    {
        return $this->store->where('ns', $this->ns)->where('key', $offset)->isNotEmpty();
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->get($offset);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->put($offset, $value, strtotime('+10 years'));
    }

    public function offsetUnset(mixed $offset): void
    {
        $this->forget($offset);
    }

    public function count(): int
    {
        return $this->store->where('ns', $this->ns)->count();
    }

    public function hset(string $key, string $field, mixed $value, int $seconds = 0): self
    {
        $this->store->updateOrCreate([
            'ns' => $this->ns,
            'key' => $key,
            'field' => $field,
        ], [
            'value' => $value,
            'ttl' => $seconds ? time() + $seconds : strtotime('+10 years'),
        ]);

        return $this;
    }

    public function hget(string $key, string $field): mixed
    {
        if ($row = $this->store->where('ns', $this->ns)->where('key', $key)->where('field', $field)->first()) {
            $ttl = $row->ttl;

            if ($ttl && $ttl < time()) {
                $this->forget($key);

                return null;
            }

            return value($row->value);
        }

        return null;
    }

    public function hgetOr(string $key, string $field, mixed $otherwise = null): mixed
    {
        return $this->hget($key, $field) ?? value($otherwise);
    }

    public function hdel(string $key, string $field): bool
    {
        if ($status = $this->hexists($key, $field)) {
            $this->store->where('ns', $this->ns)->where('key', $key)->where('field', $field)->delete();
        }

        return $status;
    }

    public function hexists(string $key, string $field): bool
    {
        return $this->store->where('ns', $this->ns)->where('key', $key)->where('field', $field)->isNotEmpty();
    }

    public function hgetall(string $key): array
    {
        return $this->store->where('ns', $this->ns)->where('key', $key)->pluck('value', 'field')->toArray();
    }

    public function hkeys(string $key): array
    {
        return $this->store->where('ns', $this->ns)->where('key', $key)->pluck('field')->toArray();
    }

    public function hvalues(string $key): array
    {
        return $this->store->where('ns', $this->ns)->where('key', $key)->pluck('value')->toArray();
    }

    public function hlen(string $key): int
    {
        return $this->store->where('ns', $this->ns)->where('key', $key)->count();
    }
}
