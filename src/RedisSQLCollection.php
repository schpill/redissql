<?php

namespace Morbihanet\RedisSQL;

use Closure;
use Generator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\LazyCollection;
use Illuminate\Support\Str;
use InvalidArgumentException;

class RedisSQLCollection extends LazyCollection
{
    public ?array $computed = null;
    public ?RedisSQL $model = null;

    public function __construct($source = null)
    {
        parent::__construct($source);

        if ($source instanceof Closure || $source instanceof LazyCollection) {
            $this->guessModel($source);
            $this->source = $source;
        } elseif (is_null($source)) {
            $this->source = static::empty();
        } elseif ($source instanceof Generator) {
            throw new InvalidArgumentException(
                'Generators should not be passed directly to RedisSQLCollection. Instead, pass a generator function.'
            );
        } else {
            $this->source = $this->getArrayableItems($source);
        }
    }

    private function guessModel(mixed $source): bool
    {
        if (is_null($this->model)) {
            if ($source instanceof Closure) {
                $reflected = new \ReflectionFunction($source);
                $thisSource = $reflected->getClosureThis();

                if ($thisSource instanceof RedisSQL) {
                    $this->model = $thisSource;
                } elseif ($thisSource instanceof RedisSQLCollection
                    or $thisSource instanceof RedisSQLQueryBuilder
                    or $thisSource instanceof RedisSQLFactory
                ) {
                    $this->model = $thisSource->model;
                }
            } elseif ($this->model instanceof RedisSQL && $source instanceof LazyCollection) {
                $this->model = $source->model;
            }

            if (is_null($this->model)) {
                foreach (debug_backtrace() as $trace) {
                    if (isset($trace['object']) && $trace['object'] instanceof RedisSQLQueryBuilder) {
                        $this->model = $trace['object']->model;

                        break;
                    } else if (isset($trace['object']) && $trace['object'] instanceof RedisSQLFactory) {
                        $this->model = $trace['object']->model;

                        break;
                    } else if (isset($trace['object']) && $trace['object'] instanceof RedisSQL) {
                        $this->model = $trace['object'];

                        break;
                    }
                }
            }
        }

        return $this->model instanceof RedisSQL;
    }

    final public function whenCustom($value, callable $callback, callable $default = null): mixed
    {
        if ($value = value($value)) {
            return $callback($this, $value);
        }

        if ($default) {
            return $default($this, $value);
        }

        return $this;
    }

    final public function naturalSort(string $column = null): LazyCollection
    {
        return $this->sortBy($column, SORT_NATURAL);
    }

    final public function naturalSortDesc(string $column = null): LazyCollection
    {
        return $this->sortByDesc($column, SORT_NATURAL);
    }

    final protected function operatorForWhere($key, $operator = null, $value = null): mixed
    {
        if ($this->useAsCallable($key)) {
            return $key;
        }

        if (func_num_args() === 1) {
            $value = true;

            $operator = '=';
        }

        if (func_num_args() === 2 or is_null($value)) {
            $value = $operator;

            $operator = '=';
        }

        return function ($item) use ($key, $operator, $value): bool {
            $retrieved = data_get($item, $key);

            $strings = array_filter([$retrieved, $value], function ($value) {
                return is_string($value) || (is_object($value) && method_exists($value, '__toString'));
            });

            if (count($strings) < 2 && count(array_filter([$retrieved, $value], 'is_object')) === 1) {
                return in_array($operator, ['!=', '<>', '!==']);
            }

            return RedisSQLUtils::compare($retrieved, $operator, $value);
        };
    }

    final public function fulltext(string $search): self
    {
        return $this->filter(function (RedisSQL $item) use ($search) {
            foreach ($item->toArray() as $value) {
                if (Str::contains(Str::lower($value), Str::lower($search))) {
                    return true;
                }
            }
        });
    }

    final public function orderBy(string $key, string $direction = 'asc'): self
    {
        return $this->sortBy($key, SORT_REGULAR, $direction === 'desc');
    }

    final public function clone(): self
    {
        return new static($this->getIterator());
    }

    final public function orderByDesc(string $key): self
    {
        return $this->clone()->sortByDesc($key);
    }

    final public function where($key, $operator = null, $value = null): self
    {
        return $this->filter(function ($item) use ($key, $operator, $value) {
            return $this->operatorForWhere($key, $operator, $value)($item);
        });
    }

    final public function orWhere(
        mixed $key,
        mixed $operator = null,
        mixed $value = null,
        bool $returnComputed = false
    ): self {
        $new = $this->eager();
        $hasId = false;

        $collection = $this->filter(function ($item) use ($key, $operator, $value, &$hasId) {
            $hasId = $hasId || ($item['id'] ?? false);

            return $this->operatorForWhere($key, $operator, $value)($item);
        })->merge($this->computed ?? []);

        if ($hasId) {
            $collection = $collection->unique('id');
        }

        $new->computed = $collection->all();

        return !$returnComputed ? $new : $collection;
    }

    final public function in(string $key, mixed $values): self
    {
        return $this->filter(function ($item) use ($key, $values) {
            return in_array(data_get($item, $key), $values);
        });
    }

    final public function xorWhere(string $key, mixed $operator = null, mixed $value = null): self
    {
        return $this->filter(function ($item) use ($key, $operator, $value) {
            return !$this->operatorForWhere($key, $operator, $value)($item);
        });
    }

    final public function nor(string $key, mixed $operator = null, mixed $value = null): self
    {
        return $this->filter(function ($item) use ($key, $operator, $value) {
            return !$this->operatorForWhere($key, $operator, $value)($item);
        });
    }

    final public function whereDate(string $key, string $operator, string $value): self
    {
        return $this->filter(function ($item) use ($key, $operator, $value) {
            if (!$date = data_get($item, $key)) {
                return false;
            }

            $date = RedisSQLUtils::localDate($date);

            return RedisSQLUtils::compare($date, $operator, $value);
        });
    }

    final public function update(array $data): self
    {
        return $this->each(function (RedisSQL $item) use ($data) {
            foreach ($data as $key => $value) {
                $item[$key] = $value;
            }

            $item->save();
        })->fresh();
    }

    final public function touch(): self
    {
        return $this->map(fn (RedisSQL $item) => $item->touch());
    }

    final public function fresh(): self
    {
        return $this->map(fn (RedisSQL $item) => $item->fresh());
    }

    final public function delete(): bool
    {
        if ($this->isNotEmpty()) {
            $ids = $this->pluck('id')->toArray();

            $this->each(function (RedisSQL $item) {
                $item->delete();
            });

            return $this->model->findMany($ids)->isEmpty();
        }

        return false;
    }

    final public function index(bool $withTimestamps = false): self
    {
        $this->each(fn (RedisSQL $item) => $item->index($withTimestamps));

        return $this;
    }

    final public function unindex(): self
    {
        $this->each(fn (RedisSQL $item) => $item->unindex());

        return $this;
    }

    final public function first(callable $callback = null, $default = null): ?RedisSQL
    {
        return parent::first($callback, $default);
    }

    final public function last(callable $callback = null, $default = null): ?RedisSQL
    {
        return parent::last($callback, $default);
    }

    final public function create(): RedisSQL|static
    {
        $coll = $this->map(fn (RedisSQL $item) => $item->save());

        if ($coll->count() === 1) {
            return $coll->first()->fresh();
        }

        return $coll->fresh();
    }

    final public function getComputed(): self
    {
        return (new static($this->computed))->setModel($this->model);
    }

    final public function customQuery(string $key, callable $value): self
    {
        return $this->filter(fn ($item) => RedisSQLUtils::compare($item[$key], 'custom', $value));
    }

    final public function custom(callable $query): self
    {
        return $this->filter($query);
    }

    final public function latest(string $column = 'id'): self
    {
        return $this->sortByDesc($column);
    }

    final public function oldest(string $column = 'id'): self
    {
        return $this->sortBy($column);
    }

    final public function trashed(): self
    {
        return $this->filter(fn (RedisSQL $item) => $item->deleted_at !== null);
    }

    final public function untrashed(): self
    {
        return $this->filter(fn (RedisSQL $item) => $item->deleted_at === null);
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

            if ($related instanceOf RedisSQL) {
                if ($related && !empty($relations)) {
                    $related = $related->deep($relations);
                }
            } else {
                if ($related->count() === 1) {
                    $related = $related->first()->deep($relations);
                } else {
                    $related = $related->deep($relations);
                }
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

    final public function select(mixed $columns = '*'): self
    {
        if ('*' === $columns) {
            return $this;
        }

        $columns = is_string($columns) ? func_get_args() : $columns;

        if (!in_array('id', $columns)) {
            $columns = array_merge($columns, ['id']);
        }

        return $this->map(function (RedisSQL $item) use ($columns) {
            $row = [];

            foreach ($columns as $column) {
                $row[$column] = $item[$column] ?? null;
            }

            return $item->newImmutable($row);
        });
    }

    final public function whereRelated(string $relation, callable $callback): self
    {
        return $this->filter(function (RedisSQL $item) use ($relation, $callback) {
            $results = $item->{$relation}();

            return $callback($results)->count() > 0;
        });
    }

    final public function whereHas(string $relation, ?callable $callback = null): self
    {
        return $this->filter(function (RedisSQL $item) use ($relation, $callback) {
            $results = $item->{$relation}();

            if ($results instanceof RedisSQLCollection) {
                return $callback ? $callback($results)->count() > 0 : $results->count() > 0;
            }

            return $callback ? $callback($results) !== null : $results !== null;
        });
    }

    final public function whereDoesntHave(string $relation, ?callable $callback = null): self
    {
        return $this->filter(function (RedisSQL $item) use ($relation, $callback) {
            $results = $item->{$relation}();

            if ($results instanceof RedisSQLCollection) {
                return $callback ? $callback($results)->count() === 0 : $results->count() === 0;
            }

            return $callback ? $callback($results) === null : $results === null;
        });
    }

    final public function whereDay(string $key, string $operator, string $value): self
    {
        return $this->filter(function ($item) use ($key, $operator, $value) {
            if (!$date = data_get($item, $key)) {
                return false;
            }

            $date = RedisSQLUtils::localDate($date);
            $dayDate = $date->format('d');
            $dayDate = str_starts_with($dayDate, '0') ? substr($dayDate, 1) : $dayDate;
            $value = str_starts_with($value, '0') ? substr($value, 1) : $value;

            return RedisSQLUtils::compare($dayDate, $operator, $value);
        });
    }

    final public function whereMonth(string $key, string $operator, string $value): self
    {
        return $this->filter(function ($item) use ($key, $operator, $value) {
            if (!$date = data_get($item, $key)) {
                return false;
            }

            $date = RedisSQLUtils::localDate($date);
            $monthDate = $date->format('m');
            $monthDate = str_starts_with($monthDate, '0') ? substr($monthDate, 1) : $monthDate;
            $value = str_starts_with($value, '0') ? substr($value, 1) : $value;

            return RedisSQLUtils::compare($monthDate, $operator, $value);
        });
    }

    final public function whereYear(string $key, string $operator, string $value): self
    {
        return $this->filter(function ($item) use ($key, $operator, $value) {
            if (!$date = data_get($item, $key)) {
                return false;
            }

            $date = RedisSQLUtils::localDate($date);
            $yearDate = $date->format('Y');

            return RedisSQLUtils::compare($yearDate, $operator, $value);
        });
    }

    final public function whereTime(string $key, string $operator, string $value): self
    {
        return $this->filter(function ($item) use ($key, $operator, $value) {
            if (!$date = data_get($item, $key)) {
                return false;
            }

            $date = RedisSQLUtils::localDate($date);
            $timeDate = $date->format('H:i:s');

            return RedisSQLUtils::compare($timeDate, $operator, $value);
        });
    }

    final public function whereWeek(string $key, string $operator, string $value): self
    {
        return $this->filter(function ($item) use ($key, $operator, $value) {
            if (!$date = data_get($item, $key)) {
                return false;
            }

            $date = RedisSQLUtils::localDate($date);
            $weekDate = $date->format('W');
            $weekDate = str_starts_with($weekDate, '0') ? substr($weekDate, 1) : $weekDate;
            $value = str_starts_with($value, '0') ? substr($value, 1) : $value;

            return RedisSQLUtils::compare($weekDate, $operator, $value);
        });
    }

    final public function whereDayOfWeek(string $key, string $operator, string $value): self
    {
        return $this->filter(function ($item) use ($key, $operator, $value) {
            if (!$date = data_get($item, $key)) {
                return false;
            }

            $date = RedisSQLUtils::localDate($date);
            $dayOfWeekDate = $date->format('N');
            $dayOfWeekDate = str_starts_with($dayOfWeekDate, '0') ? substr($dayOfWeekDate, 1) : $dayOfWeekDate;
            $value = str_starts_with($value, '0') ? substr($value, 1) : $value;

            return RedisSQLUtils::compare($dayOfWeekDate, $operator, $value);
        });
    }

    final public function whereDayOfYear(string $key, string $operator, string $value): self
    {
        return $this->filter(function ($item) use ($key, $operator, $value) {
            if (!$date = data_get($item, $key)) {
                return false;
            }

            $date = RedisSQLUtils::localDate($date);
            $dayOfYearDate = $date->format('z');
            $dayOfYearDate = str_starts_with($dayOfYearDate, '0') ? substr($dayOfYearDate, 1) : $dayOfYearDate;
            $value = str_starts_with($value, '0') ? substr($value, 1) : $value;

            return RedisSQLUtils::compare($dayOfYearDate, $operator, $value);
        });
    }

    final public function whereQuarter(string $key, string $operator, string $value): self
    {
        return $this->filter(function ($item) use ($key, $operator, $value) {
            if (!$date = data_get($item, $key)) {
                return false;
            }

            $date = RedisSQLUtils::localDate($date);
            $quarterDate = $date->format('q');
            $quarterDate = str_starts_with($quarterDate, '0') ? substr($quarterDate, 1) : $quarterDate;
            $value = str_starts_with($value, '0') ? substr($value, 1) : $value;

            return RedisSQLUtils::compare($quarterDate, $operator, $value);
        });
    }

    final public function whereDateEquals(string $key, string $value, string $format = 'Y-m-d'): self
    {
        return $this->filter(function ($item) use ($key, $value, $format) {
            if (!$date = data_get($item, $key)) {
                return false;
            }

            $date = RedisSQLUtils::localDate($date);
            $dateDate = $date->format($format);

            return $dateDate === $value;
        });
    }

    final public function firstOrFailWhere(string $key, mixed $operator = null, mixed $value = null): RedisSQL
    {
        return $this->firstWhere($key, $operator, $value) ?? throw new ModelNotFoundException;
    }

    final public function findBy(string $key, $value): self
    {
        return $this->where($key, $value);
    }

    final public function findOneBy(string $key, $value): ?RedisSQL
    {
        return $this->findBy($key, $value)->first();
    }

    public static array $scopes = [];

    public static function scope(string $name, RedisSQL $model, Closure $callback): void
    {
        static::$scopes[$model->guessTable()][$name] = $callback;
    }

    public function __call($method, $parameters): mixed
    {
        if ($callable = (static::$scopes[$this->model?->guessTable()][$method] ?? null)) {
            return $callable($this, ...$parameters);
        }

        if (str_starts_with($method, 'findBy') && strlen($method) > 6) {
            $column = Str::snake(substr($method, 6));

            if (empty($parameters)) {
                return $this->where($column, 'is not', null);
            }

            return $this->findBy($column, $parameters[0]);
        }

        if (str_starts_with($method, 'findOneBy') && strlen($method) > 9) {
            $column = Str::snake(substr($method, 9));

            if (empty($parameters)) {
                return $this->where($column, 'is not', null)->first();
            }

            return $this->findOneBy($column, $parameters[0]);
        }

        if (str_starts_with($method, 'sortBy') && strlen($method) > 6) {
            $column = Str::snake(substr($method, 6));

            return $this->sortBy($column);
        }

        if (str_starts_with($method, 'sortByDesc') && strlen($method) > 10) {
            $column = Str::snake(substr($method, 10));

            return $this->sortByDesc($column);
        }

        if (str_starts_with($method, 'where') && strlen($method) > 5) {
            $column = Str::snake(substr($method, 5));

            return $this->where($column, ...$parameters);
        }

        if (str_starts_with($method, 'orWhere') && strlen($method) > 7) {
            $column = Str::snake(substr($method, 7));

            return $this->orWhere($column, ...$parameters);
        }

        if (str_starts_with($method, 'xorWhere') && strlen($method) > 8) {
            $column = Str::snake(substr($method, 8));

            return $this->xorWhere($column, ...$parameters);
        }

        if (str_starts_with($method, 'firstWhere') && strlen($method) > 10) {
            $column = Str::snake(substr($method, 10));

            return $this->firstWhere($column, ...$parameters);
        }

        return parent::__call($method, $parameters);
    }

    final public function engine(): mixed
    {
        return $this->model?->engine();
    }

    final public function getModel(): ?RedisSQL
    {
        return $this->model;
    }

    final public function setModel(RedisSQL $model): self
    {
        $this->model = $model;

        return $this;
    }
}
