<?php

namespace Morbihanet\RedisSQL;

class RedisSQLCache
{
    public function __construct(protected RedisSQLQueryBuilder $builder, protected int $ttlInMinutes = 0) {}

    public function getOrCreate(string $key, ?callable $fallback = null)
    {
        $redis = $this->builder->model->engine();

        if ($redis->exists($k = $this->builder->model->redisKey('rsql.kh.' . $this->model->guessTable() . '.' . $key)
        )) {
            return json_decode($redis->get($k), true);
        }

        if (is_callable($fallback)) {
            if ($this->ttlInMinutes > 0) {
                $redis->setex($k, $this->ttlInMinutes * 60, json_encode($result = $fallback($this->builder->model)));

                return $result;
            }

            $redis->set($k, json_encode($result = $fallback($this->builder->model)));

            return $result;
        }

        return null;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $redis = $this->builder->model->engine();

        if ($redis->exists($k = $this->builder->model->redisKey('rsql.kh.' . $this->builder->model->guessTable() . '.' . $key))) {
            return json_decode($redis->get($k), true);
        }

        return value($default);
    }

    public function set(string $key, mixed $value): RedisSQLQueryBuilder
    {
        $redis = $this->builder->model->engine();

        if ($this->ttlInMinutes > 0) {
            $redis->setex($this->builder->model->redisKey('rsql.kh.' . $this->builder->model->guessTable() . '.' . $key), $this->ttlInMinutes * 60, json_encode($value));
        } else {
            $redis->set(
                $this->builder->model->redisKey('rsql.kh.' . $this->builder->model->guessTable() . '.' . $key),
                json_encode($value)
            );
        }

        return $this->builder;
    }

    public function has(string $key): bool
    {
        return (bool) $this->builder->model->engine()->exists(
            $this->builder->model->redisKey('rsql.kh.' . $this->builder->model->guessTable() . '.' . $key)
        );
    }

    public function forget(string $key): bool
    {
        if ($status = $this->has($key)) {
            $this->builder->model->engine()->del(
                $this->builder->model->redisKey('rsql.kh.' . $this->builder->model->guessTable() . '.' . $key)
            );
        }

        return $status;
    }

    public function flush(): bool
    {
        $redis = $this->builder->model->engine();

        foreach ($redis->keys(
            $this->builder->model->redisKey($pattern = 'rsql.kh.' . $this->builder->model->guessTable() . '.*')
        ) as $key) {
            $redis->del($key);
        }

        return collect($redis->keys(
            $this->builder->model->redisKey($pattern)
        ))->isEmpty();
    }
}
