<?php

namespace Morbihanet\RedisSQL;

use Closure;
use Exception;
use ReflectionFunction;

/**
 * @mixin RedisSQLCollection
 */
class RedisSQLQueryBuilder
{
    public function __construct(public RedisSQL $model) {}

    final public function create(array $attributes = [], bool $immutable = false): RedisSQL
    {
        $class = get_class($this->model);
        $model = (new $class($attributes))->useTable($this->model->guessTable());

        $row = $model->save();

        if ($immutable) {
            $row = $row->immutable();
        }

        return $row;
    }

    final public function createImmutable(array $data = []): RedisSQL
    {
        return $this->create($data, true);
    }

    final public function new(array $data): RedisSQL
    {
        return $this->model->forceFill($data);
    }

    final public function newImmutable(array $data): RedisSQL
    {
        return $this->model->forceFill($data)->immutable();
    }

    final public function find(int $id, bool $immutable = false): ?RedisSQL
    {
        $attributes = $this->engine()->hget($this->model->redisKey('rows'), $id);

        if ($attributes) {
            $class = get_class($this->model);
            $row = (new $class(json_decode($attributes, true)))->useTable($this->model->guessTable());

            if ($immutable) {
                $row = $row->immutable();
            }

            return $row;
        }

        return null;
    }

    final public function findMany($ids, bool $immutable = false): RedisSQLCollection
    {
        $db = function () use ($ids, $immutable) {
            foreach ($ids as $id) {
                if ($row = $this->find($id, $immutable)) {
                    yield $row;
                }
            }
        };

        return (new RedisSQLCollection($db))->setModel($this->model);
    }

    final public function findOrFail(int|string $id, bool $immutable = false): RedisSQL
    {
        if ($row = $this->find($id, $immutable)) {
            return $row;
        }

        throw new \Exception('Record not found');
    }

    final public function findBy(string $field, string $value): RedisSQLCollection
    {
        return $this->where($field, $value);
    }

    final public function findOneBy(string $field, string $value, bool $immutable = false): ?RedisSql
    {
        $row = $this->where($field, $value)->first();

        if ($row && $immutable) {
            $row = $row->immutable();
        }

        return $row;
    }

    final public function updateOrCreate(array $conditions, array $data): RedisSQL
    {
        $row = $this->firstOrCreate($conditions);

        return $row->forceFill($data)->save();
    }

    final public function fuzzy_match(string $string, string $pattern): bool
    {
        $pattern = preg_quote($pattern, '/');
        $pattern = str_replace('\*', '.*', $pattern);
        $pattern = str_replace('\?', '.', $pattern);

        return (bool) preg_match("/^{$pattern}$/i", $string);
    }

    final public function destroy(int $id): bool
    {
        if ($row = $this->find($id)) {
            return $row->delete();
        }

        return false;
    }

    final public function firstOrCreate(array $attributes, bool $immutable = false): RedisSQL
    {
        $query = (new RedisSQLCollection)->setModel($this->model);

        foreach ($attributes as $key => $value) {
            $query = $query->where($key, $value);
        }

        if (!$model = $query->first()) {
            $class = get_class($this->model);
            $model = (new $class($attributes))->useTable($this->model->guessTable());
            $model->save();

            if ($immutable) {
                $model = $model->immutable();
            }

        }

        return $model;
    }

    final public function firstOrNew(array $attributes, bool $immutable = false): RedisSQL
    {
        $query = (new RedisSQLCollection)->setModel($this->model);

        foreach ($attributes as $key => $value) {
            $query = $query->where($key, $value);
        }

        if (!$model = $query->first()) {
            $class = get_class($this->model);
            $model = (new $class($attributes))->useTable($this->model->guessTable());

            if ($immutable) {
                $model = $model->immutable();
            }
        }

        return $model;
    }

    /**
     * @return RedisSQLFileCache|\Illuminate\Redis\RedisManager
     */
    final public function engine(): mixed
    {
        return $this->model?->engine();
    }

    final public function all(bool $immutable = false): RedisSQLCollection
    {
        $db = function () use ($immutable) {
            $class = get_class($this->model);
            $rows = $this->engine()->hgetall($this->model->redisKey('rows'));

            foreach ($rows as $row) {
                $value = (new $class(json_decode($row, true)))->useTable($this->model->guessTable());

                if ($immutable) {
                    $value = $value->immutable();
                }

                yield $value;
            }
        };

        return (new RedisSQLCollection($db))->setModel($this->model);
    }

    final public function query(bool $immutable = false): RedisSQLCollection
    {
        return $this->all($immutable);
    }

    final public function drop(): bool
    {
        $redis = $this->engine();

        $redis->del($this->model->redisKey('rows'));
        $redis->del($this->model->redisKey('id'));
        $redis->del($this->model->redisKey('lastchange'));

        if (in_array('destroyDirectory', get_class_methods($redis))) {
            $redis->destroyDirectory();
        }

        return $this->isEmpty();
    }

    final public function getLastInsertId(): int
    {
        return (int) $this->engine()->get($this->model->redisKey('id'));
    }

    final public function beginTransaction(): void
    {
        $this->engine()->multi();
    }

    final public function commit(): void
    {
        $this->engine()->exec();
    }

    final public function rollback(): void
    {
        $this->engine()->discard();
    }

    final public function transaction(Closure $callback): mixed
    {
        $this->beginTransaction();

        try {
            $result = $callback($this->model);

            $this->commit();

            return $result;
        } catch (Exception $e) {
            $this->rollback();

            throw $e;
        }
    }

    final public function cache(callable $callback, int $ttlMinutes = 60, bool $salted = false): mixed
    {
        $redis = $this->engine();
        $ref = new ReflectionFunction($callback);

        $parameters = $ref->getParameters();
        $startLine = $ref->getStartLine();
        $endLine = $ref->getEndLine();
        $filename = $ref->getFileName();
        $lastChange = $redis->get($this->model->redisKey('lastchange')) ?? 0;

        if ($salted) {
            $codeFromStartToEnd = implode('', array_slice(file($filename), $startLine - 1, $endLine - $startLine + 1));
            $fingerPrint = sha1($filename . $codeFromStartToEnd . serialize($parameters) . $lastChange);
        } else {
            $fingerPrint = sha1($filename . $startLine . $endLine . serialize($parameters) . $lastChange);
        }

        if ($redis->exists($key = $this->model->redisKey('rsql.kh.' . $this->model->guessTable() . '.' . $fingerPrint))) {
            return json_decode($redis->get($key), true);
        }

        if ($ttlMinutes > 0) {
            $redis->setex($key, $ttlMinutes * 60, json_encode($result = $callback($this->model)));
        } else {
            $redis->set($key, json_encode($result = $callback($this->model)));
        }

        return $result;
    }

    final public function remember(int $ttlMinutes = 0): RedisSQLCache
    {
        return new RedisSQLCache($this, $ttlMinutes);
    }

    final public function flushCache(): bool
    {
        $redis = $this->engine();

        $redis->del($redis->keys($this->model->redisKey('rsql.kh.*')));

        return count($redis->keys($this->model->redisKey('rsql.kh.*'))) === 0;
    }

    final public function has(string $relation): bool
    {
        return $this->whereHas($relation)->isNotEmpty();
    }

    final public function doesntHave(string $relation): bool
    {
        return !$this->has($relation);
    }

    public function __call(string $name, array $arguments): mixed
    {
        if (str_starts_with($name, 'orderByDesc') && strlen($name) > 11) {
            $field = substr($name, 11);
            $field = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $field));

            return $this->orderBy($field, 'desc')->setModel($this->model);
        }

        if (str_starts_with($name, 'orderBy') && strlen($name) > 7) {
            $field = substr($name, 7);
            $field = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $field));

            return $this->orderBy($field, $arguments[0]  ?? 'asc')->setModel($this->model);
        }

        if (str_starts_with($name, 'groupBy') && strlen($name) > 7) {
            $field = substr($name, 7);
            $field = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $field));

            return $this->groupBy($field, $arguments[0] ?? false)->setModel($this->model);
        }

        if (str_starts_with($name, 'where') && strlen($name) > 5) {
            $field = substr($name, 5);
            $field = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $field));

            return $this->where($field, ...$arguments)->setModel($this->model);
        }

        return $this->all()->setModel($this->model)->{$name}(...$arguments);
    }

    final public function with(mixed $relations): self
    {
        $relations = is_string($relations) ? func_get_args() : $relations;

        return $this->map(function (RedisSQL $item) use ($relations) {
            foreach ($relations as $relation) {
                if (fnmatch('*.*', $relation)) {
                    /** @var RedisSQL|RedisSQLCollection $related */
                    $related = $this->deep(explode('.', $relation));
                } else {
                    /** @var RedisSQL|RedisSQLCollection $related */
                    $related = $item->{$relation}();
                }

                $item->info('related_' . $relation, $related);
            }

            return $item;
        });
    }

    final public function deep(mixed $relations): self
    {
        $relations = is_string($relations) ? func_get_args() : $relations;

        $relation = array_shift($relations);

        return $this->map(function (RedisSQL $item) use ($relation, $relations) {
            /** @var RedisSQL|RedisSQLCollection $related */
            $related = $item->{$relation}();

            if ($related && !empty($relations)) {
                $related = $related->deep($relations);
            }

            if ($related instanceOf RedisSQL) {
                $related = $related->fresh();
            } else {
                if ($related->count() === 1) {
                    $related = $related->first()->fresh();
                } else {
                    $related = $related->fresh();
                }
            }

            $item->info('related_' . $relation, $related);

            return $item;
        });
    }
}
