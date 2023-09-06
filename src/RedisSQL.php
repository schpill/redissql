<?php

namespace Morbihanet\RedisSQL;

use ArrayAccess;
use DateTimeInterface;
use Exception;
use Illuminate\Support\Carbon;
use Meilisearch\Endpoints\Indexes;
use Meilisearch\Search\SearchResult;

/**
 * @mixin RedisSqlQueryBuilder
 */
class RedisSQL implements ArrayAccess
{
    protected ?string $_table = null;
    protected array $_original = [];
    protected array $_attributes = [];
    protected array $_infos = [];

    public function __construct(array $attributes = [])
    {
        $this->_original = $attributes;
        $this->_attributes = $attributes;
    }

    final public function info(string $key, mixed $value = '__dummy__'): mixed
    {
        if ($value === '__dummy__'){
            return value($this->_infos[$key] ?? null);
        }

        $this->_infos[$key] = $value;

        return $this;
    }

    final public function related(string $relation): mixed
    {
        return $this->_infos['related_' . $relation] ?? null;
    }

    final public function useTable(string $table): self
    {
        $this->_table = $table;

        return $this;
    }

    final public function save(): self
    {
        if ($this->isDirty() && !$this->isImmutable()) {
            $this->fire('saving');

            if ($this->exists()) {
                $updated = $this->edit();
                $updated->fire('saved');

                return $updated;
            } else {
                $created = $this->insert();
                $created->fire('saved');

                return $created;
            }
        }

        return $this;
    }

    final public function offsetExists(mixed $offset): bool
    {
        return $this->__isset($offset);
    }

    final public function offsetGet(mixed $offset): mixed
    {
        return $this->__get($offset);
    }

    final public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->__set($offset, $value);
    }

    final public function offsetUnset(mixed $offset): void
    {
        $this->__unset($offset);
    }

    final protected function exists(): bool
    {
        return (bool) ($this->_attributes['id'] ?? false);
    }

    final protected function edit(): self
    {
        $this->fire('updating');
        $this->_attributes['updated_at'] = now()->getTimestamp();

        $updated = $this->storeRow();

        $updated->fire('updated');

        return $updated;
    }

    final protected function insert(): self
    {
        $this->fire('creating');
        $this->_attributes['id'] = $this->generateId();
        $this->_attributes['created_at'] = now()->getTimestamp();
        $this->_attributes['updated_at'] = now()->getTimestamp();

        $inserted = $this->storeRow();

        $inserted->fire('created');

        return $inserted;
    }

    final public function delete(): bool
    {
        if ($this->isImmutable()) {
            return false;
        }

        $this->fire('deleting');
        $this->engine()->hdel($this->redisKey('rows'), $this->_attributes['id']);

        $this->updateLastChange();

        $this->fire('deleted');

        return !$this->engine()->hget($this->redisKey('rows'), $this->_attributes['id']);
    }

    final public function softDelete(): bool
    {
        if ($this->isImmutable()) {
            return false;
        }

        $this->fire('deleting');
        $this->_attributes['deleted_at'] = RedisSQLUtils::now()->getTimestamp();

        $this->updateLastChange();

        $this->fire('deleted');

        return $this->fresh()['deleted_at'] !== null;
    }

    final public function guessTable(): string
    {
        return $this->_table ?? RedisSQLUtils::uncamelize(static::class);
    }

    final public function redisKey(string $key): string
    {
        return 'redissql.' . $this->guessTable() . '.' . $key;
    }

    final public function generateId(): int
    {
        return (int) $this->engine()->incr($this->redisKey('id'));
    }

    final public function updateLastChange(): void
    {
        $this->engine()->set($this->redisKey('lastchange'), now()->getTimestamp());
    }

    final public function toArray(): array
    {
        return $this->_attributes;
    }

    final public function storeRow(): self
    {
        $this->engine()->hset($this->redisKey('rows'), $this->_attributes['id'], json_encode($this->_attributes));

        $this->updateLastChange();

        return (new static($this->_attributes))->useTable($this->guessTable());
    }

    final public function touch(): self
    {
        $this->_attributes['updated_at'] = now()->getTimestamp();

        return $this->storeRow();
    }

    final public function fresh(): self
    {
        if ($this->exists()) {
            return (new static(
                json_decode($this->engine()->hget($this->redisKey('rows'), $this->_attributes['id']), true)
            ))->useTable($this->guessTable());
        }

        return $this;
    }

    final public function fill(array $attributes): void
    {
        $this->_original = array_merge($this->_original, $attributes);
        $this->_attributes = array_merge($this->_attributes, $attributes);
    }

    final public function update(array $data): self
    {
        if ($this->isImmutable()) {
            return $this;
        }

        $this->fire('updating');
        $this->_attributes = array_merge($this->_attributes, $data);

        $updated = $this->save();

        $this->fire('updated');

        return $updated;
    }

    final public function getId(): ?int
    {
        return $this->_attributes['id'] ?? null;
    }

    final public function forceFill(array $attributes): self
    {
        $this->_attributes = array_merge($this->_attributes, $attributes);

        return $this;
    }

    final public function isDirty(): bool
    {
        return !isset($this['id']) or $this->_original !== $this->_attributes;
    }

    final public function duplicate(): self
    {
        if ($this->isImmutable()) {
            return $this;
        }

        $this->fire('duplicating');
        $attributes = $this->_attributes;
        unset($attributes['id'], $attributes['created_at'], $attributes['updated_at']);

        $duplicate = (new static($attributes))->useTable($this->guessTable())->save();
        $duplicate->fire('duplicated');

        return $duplicate;
    }

    final public function belongsTo(string $modelClass, ?string $foreign_key = null): ?static
    {
        $model = static::forTable($modelClass);

        return $model->find($this[$foreign_key ?? $model->guessTable() . '_id']);
    }

    final public function hasMany(string $modelClass, ?string $foreign_key = null): RedisSQLCollection
    {
        return static::forTable($modelClass)->where($foreign_key ?? $this->guessTable() . '_id', $this['id']);
    }

    final public function belongsToMany(
        string $modelClass,
        ?string $pivot = null,
        ?string $fk1 = null,
        ?string $fk2 = null
    ): RedisSQLCollection {
        $model = static::forTable($modelClass);
        $classes = collect([$this->guessTable(), $model->guessTable()])->sort()->toArray();

        $ids = static::forTable($pivot ?? $classes[0] . '_' . $classes[1])
            ->where($fk1 ?? $this->guessTable() . '_id', $this['id'])->pluck($fk2 ?? $model->guessTable() . '_id');

        return $model->findMany($ids->values()->toArray());
    }

    final public function morphMany(
        string $morphName,
        ?string $type = null,
        ?string $id = null,
        ?string $ownerKey = null
    ): RedisSQLCollection {
        $model = static::forTable($morphName);
        $able = $model->guessTable() . 'able';
        $type ??= $able . '_type';
        $id ??= $able . '_id';

        return $model->where($type, $this->guessTable())->where($id, $this[$ownerKey ?? 'id']);
    }

    final public function morphedByMany(
        string $morphName,
        ?string $type = null,
        ?string $id = null,
        ?string $ownerKey = null
    ): RedisSQLCollection {
        return $this->morphMany($morphName, $type, $id, $ownerKey);
    }

    final public function morphOne(
        string $morphName,
        ?string $type = null,
        ?string $id = null,
        ?string $ownerKey = null
    ): ?RedisSQL {
        return $this->morphMany($morphName, $type, $id, $ownerKey)->first();
    }

    final public function hasManyThrough(
        string $modelClass,
        string $throughClass,
        ?string $fk1 = null,
        ?string $fk2 = null,
    ): RedisSQLCollection {
        $model = static::forTable($modelClass);
        $through = static::forTable($throughClass);
        $classes = collect([$this->guessTable(), $model->guessTable(), $through->guessTable()])->sort()->toArray();

        $ids = static::forTable($classes[0] . '_' . $classes[1])
            ->where($fk1 ?? $this->guessTable() . '_id', $this['id'])
            ->pluck($fk2 ?? $model->guessTable() . '_id');

        return $model->findMany($ids->values()->toArray());
    }

    final public function hasOne(string $modelClass, ?string $foreign_key = null): ?RedisSQL
    {
        $model = static::forTable($modelClass);

        return $model->where($foreign_key ?? $this->guessTable() . '_id', $this['id'])->first();
    }

    final public function hasOneThrough(
        string $modelClass,
        string $throughClass,
        ?string $fk1 = null,
        ?string $fk2 = null,
    ): ?RedisSQL {
        $model = static::forTable($modelClass);
        $through = static::forTable($throughClass);
        $classes = collect([$this->guessTable(), $model->guessTable(), $through->guessTable()])->sort()->toArray();

        $id = static::forTable($classes[0] . '_' . $classes[1])
            ->where($fk1 ?? $this->guessTable() . '_id', $this['id'])
            ->pluck($fk2 ?? $model->guessTable() . '_id')
            ->first();

        return $model->find($id);
    }

    final public function hasManyMorph(
        string $morphName,
        ?string $type = null,
        ?string $id = null,
        ?string $ownerKey = null
    ): RedisSQLCollection {
        $model = static::forTable($morphName);
        $able = $model->guessTable() . 'able';
        $type ??= $able . '_type';
        $id ??= $able . '_id';

        return $model->where($type, $this->guessTable())->where($id, $this[$ownerKey ?? 'id']);
    }

    public array $_observers = [];

    final public function observe(string $event, callable $callback): self
    {
        $this->_observers[$event] = $callback;

        return $this;
    }

    final public function flushEventListeners(): self
    {
        $this->_observers = [];

        return $this;
    }

    final public function retrieved(callable $callback): self
    {
        return $this->observe('retrieved', $callback);
    }

    final public function saving(callable $callback): self
    {
        return $this->observe('saving', $callback);
    }

    final public function saved(callable $callback): self
    {
        return $this->observe('saved', $callback);
    }

    final public function creating(callable $callback): self
    {
        return $this->observe('creating', $callback);
    }

    final public function created(callable $callback): self
    {
        return $this->observe('created', $callback);
    }

    final public function updating(callable $callback): self
    {
        return $this->observe('updating', $callback);
    }

    final public function updated(callable $callback): self
    {
        return $this->observe('updated', $callback);
    }

    final public function deleting(callable $callback): self
    {
        return $this->observe('deleting', $callback);
    }

    final public function forceDeleted(callable $callback): self
    {
        return $this->observe('forceDeleted', $callback);
    }

    final public function deleted(callable $callback): self
    {
        return $this->observe('deleted', $callback);
    }

    final public function restoring(callable $callback): self
    {
        return $this->observe('restoring', $callback);
    }

    final public function restored(callable $callback): self
    {
        return $this->observe('restored', $callback);
    }

    final public function duplicating(callable $callback): self
    {
        return $this->observe('duplicating', $callback);
    }

    final public function duplicated(callable $callback): self
    {
        return $this->observe('duplicated', $callback);
    }

    final public function restore(): self
    {
        if (isset($this['id']) && !$this->isImmutable()) {
            $this->fire('restoring');
            unset($this['deleted_at']);
            $this->save();
            $this->fire('restored');
        }

        return $this;
    }

    public static array $_allObservers = [];

    final public function fire(string $string, ?RedisSQL $param = null): mixed
    {
        $param ??= $this;
        $observers = $param->_observers ?? [];

        if (empty($observers)) {
            $observers = static::$_allObservers;
        }

        if (empty($observers)) {
            return $this;
        }

        $event = $observers[$string] ?? null;

        if (is_callable($event)) {
            return $event($param);
        }

        return $this;
    }

    public static function __callStatic(string $name, array $arguments): mixed
    {
        return static::forTable(RedisSQLUtils::uncamelize(basename(str_replace('\\', '_', static::class))))
            ->__call($name, $arguments);
    }

    public function __get(string $name): mixed
    {
        if (RedisSQLUtils::isPlural($name)) {
            if (!isset($this->_attributes[$name])) {
                return $this->hasMany(RedisSQLUtils::singularize($name));
            }
        }

        if (isset($this->_attributes[$name . '_id'])) {
            return $this->belongsTo($name);
        }

        $value = $this->_attributes[$name] ?? null;

        if (is_string($value) && str_starts_with($value, 'json:')) {
            $value = json_decode(substr($value, 5), true);
        }

        if (str_ends_with($name, '_at') && is_numeric($value)) {
            $value = RedisSQLUtils::localDate($value, false);
        }

        return $value;
    }

    public function __set(string $name, mixed $value): void
    {
        if (!$this->isImmutable()) {
            if (str_ends_with($name, '_at') && $value instanceof DateTimeInterface) {
                $value = $value->getTimestamp();
            }

            if (str_ends_with($name, '_at')) {
                if (fnmatch('*/*/* *:*:*', $value)) {
                    $value = Carbon::createFromFormat('d/m/Y H:i:s', $value)->getTimestamp();
                } elseif (fnmatch('*-*-* *:*:*', $value)) {
                    $value = Carbon::createFromFormat('Y-m-d H:i:s', $value)->getTimestamp();
                } elseif (is_string($value) && is_numeric($value)) {
                    $value = (int) $value;
                }
            }

            if (!is_array($value) && RedisSQLUtils::isJson($value)) {
                $value = 'json:' . $value;
            }

            if (RedisSQLUtils::isPlural($name)) {
                $singular = RedisSQLUtils::singularize($name);
                $table = $this->guessTable();
                $classes = collect([$table, $singular])->sort()->toArray();

                $pivot = static::forTable($classes[0] . '_' . $classes[1]);

                if (is_array($value) or $value instanceof RedisSQLCollection) {
                    $pivot->where($table . '_id', $this['id'])->delete();

                    foreach ($value as $record) {
                        $pivot->forceFill([
                            $table . '_id' => $this['id'],
                            $singular . '_id' => $record['id'],
                        ])->save();
                    }
                } else {
                    $pivot->where($table . '_id', $this['id'])->delete();

                    if ($value) {
                        $pivot->forceFill([
                            $table . '_id' => $this['id'],
                            $singular . '_id' => $value['id'],
                        ])->save();
                    }
                }
            }

            $this->_attributes[$name] = $value;
        }
    }

    public function __isset(string $name): bool
    {
        if (!RedisSQLUtils::isPlural($name)) {
            return isset($this->_attributes[$name]) || isset($this->_attributes[$name . '_id']);
        }

        return $this->hasMany(RedisSQLUtils::singularize($name))->isNotEmpty();
    }

    public function __unset(string $name): void
    {
        if (!$this->isImmutable()) {
            if (!RedisSQLUtils::isPlural($name)) {
                unset($this->_attributes[$name]);
            } else {
                $this->hasMany(RedisSQLUtils::singularize($name))->delete();
            }
        }
    }

    final public function contains(array $data): bool
    {
        return $this->search($data)->isNotEmpty();
    }

    final public function search(array $data): RedisSQLCollection
    {
        $query = (new RedisSQLCollection)->setModel($this);

        foreach ($data as $field => $value) {
            $query = $query->where($field, $value);
        }

        return $query;
    }

    final public function sync(RedisSQL $record, array $attributes = []): void
    {
        $modelClass = $record->guessTable();
        $classes = collect([$this->guessTable(), $modelClass])->sort()->toArray();

        $pivot = static::forTable($classes[0] . '_' . $classes[1]);

        if ($pivot->contains([
            $this->guessTable() . '_id' => $this['id'],
            $modelClass . '_id' => $record['id'],
        ])) {
            $pivot->search([
                $this->guessTable() . '_id' => $this['id'],
                $modelClass . '_id' => $record['id'],
            ])->delete();
        } else {
            $pivot->forceFill(array_merge([
                $this->guessTable() . '_id' => $this['id'],
                $modelClass . '_id' => $record['id'],
            ], $attributes))->save();
        }
    }

    final public function attach(RedisSQL $record, array $attributes = []): bool
    {
        $modelClass = $record->guessTable();
        $classes = collect([$this->guessTable(), $modelClass])->sort()->toArray();

        $pivot = static::forTable($classes[0] . '_' . $classes[1]);

        $exists = $pivot->contains([
            $this->guessTable() . '_id' => $this['id'],
            $modelClass . '_id' => $record['id'],
        ]);

        if ($exists) {
            return false;
        }

        $pivot->forceFill(array_merge([
            $this->guessTable() . '_id' => $this['id'],
            $modelClass . '_id' => $record['id'],
        ], $attributes))->save();

        return true;
    }

    final public function detach(RedisSQL $record): bool
    {
        $modelClass = $record->guessTable();
        $classes = collect([$this->guessTable(), $modelClass])->sort()->toArray();

        $pivot = static::forTable($classes[0] . '_' . $classes[1]);

        $exists = $pivot->contains([
            $this->guessTable() . '_id' => $this['id'],
            $modelClass . '_id' => $record['id'],
        ]);

        if (!$exists) {
            return false;
        }

        $pivot->search([
            $this->guessTable() . '_id' => $this['id'],
            $modelClass . '_id' => $record['id'],
        ])->delete();

        return true;
    }

    final public function morphTo(
        string $morphName,
        ?string $type = null,
        ?string $id = null,
        ?string $ownerKey = null,
        bool $many = false
    ): RedisSQL|RedisSQLCollection {
        $model = static::forTable($morphName);
        $able = $model->guessTable() . 'able';
        $type ??= $able . '_type';
        $id ??= $able . '_id';

        $results = $model->where($type, $this->guessTable())->where($id, $this[$ownerKey ?? 'id']);

        return $many ? $results : $results->first();
    }

    final public function morphToMany(
        string $morphName,
        ?string $type = null,
        ?string $id = null,
        ?string $ownerKey = null
    ): RedisSQLCollection {
        return $this->morphTo($morphName, $type, $id, $ownerKey, true);
    }

    final public function exportToCSV(string $filename): void
    {
        $csv = [array_keys($this->first()->toArray())];

        $this->each(function (RedisSQLRecord $record) use (&$csv) {
            $csv[] = $record->toArray();
        });

        $csv = array_map(function ($row) {
            return array_map(function ($value) {
                return is_string($value) ? '"' . $value . '"' : $value;
            }, $row);
        }, $csv);

        $csv = array_map(function ($row) {
            return implode(',', $row);
        }, $csv);

        $csv = implode("\n", $csv);

        file_put_contents($filename, $csv);
    }

    final public function exportToHtmlTable(
        ?string $tableClass = null,
        ?string $trClass = null,
        ?string $thClass = null,
        ?string $tdClass = null
    ): string {
        $html = !$tableClass ? '<table>' : '<table class="' . $tableClass . '">';
        $html .= '<thead>';

        $html .= !$trClass ? '<tr>' : '<tr class="' . $trClass . '">';

        foreach ($this->first()->toArray() as $field => $value) {
            $html .= !$thClass ? '<th>' . $field . '</th>' : '<th class="' . $thClass . '">' . $field . '</th>';
        }

        $html .= '</tr>';
        $html .= '</thead>';

        $html .= '<tbody>';

        $this->each(function (FileSQLRecord $record) use (&$html, $trClass, $tdClass) {
            $html .= !$trClass ? '<tr>' : '<tr class="' . $trClass . '">';

            foreach ($record->toArray() as $field => $value) {
                $html .= !$tdClass ? '<td>' . $value . '</td>' : '<td class="' . $tdClass . '">' . $value . '</td>';
            }

            $html .= '</tr>';
        });

        $html .= '</tbody>';
        $html .= '</table>';

        return $html;
    }

    final public function exportToJSON(int $option = JSON_PRETTY_PRINT): string
    {
        $json = [];

        $this->each(function (RedisSQL $record) use (&$json) {
            $json[] = $record->toArray();
        });

        return json_encode($json, $option);
    }

    final public function importFromJsonFile(string $filename): void
    {
        $json = json_decode(file_get_contents($filename), true);

        foreach ($json as $row) {
            $this->forceFill($row)->save();
        }
    }

    final public function importFromCSVFile(string $filename): void
    {
        $csv = array_map('str_getcsv', file($filename));

        $headers = array_shift($csv);

        foreach ($csv as $row) {
            $this->forceFill(array_combine($headers, $row))->save();
        }
    }

    final public function columns(): array
    {
        /** @var RedisSQL $row */
        if ($row = $this->first()) {
            return array_keys($row->toArray());
        }

        return ['id', 'created_at', 'updated_at'];
    }

    final public function hasColumn(string $column): bool
    {
        return in_array($column, $this->columns(), true);
    }

    final public function findMany(array $ids): RedisSQLCollection
    {
        $db = function () use ($ids) {
            foreach ($ids as $id) {
                if ($row = $this->find($id)) {
                    yield $row;
                }
            }
        };

        return (new RedisSQLCollection($db))->setModel($this);
    }

    protected bool $_immutable = false;

    final public function isImmutable(): bool
    {
        return $this->_immutable;
    }

    final public function immutable(bool $status = true): self
    {
        $this->_immutable = $status;

        return $this;
    }

    public $_indexData = null;

    final public function scout(): Indexes
    {
        $namespace = 'redissqlindex_';

        return RedisSQLUtils::meilisearch_index(
            RedisSQLUtils::pluralize(RedisSQLUtils::uncamelize($namespace . $this->guessTable()))
        );
    }

    final public function index(bool $withTimestamps = false): self
    {
        if (isset($this['id'])) {
            RedisSQLUtils::meilisearch_add_document($this->scout()->getUid(), $this->getIndexedData($withTimestamps));
        }

        return $this;
    }

    final public function unindex(): array
    {
        return $this->scout()->deleteDocument($this['id']);
    }

    final public function setIndexData(array|callable $data): self
    {
        $this->_indexData = $data;

        return $this;
    }

    final public function only(array $keys): self
    {
        if (!in_array('id', $keys)) {
            $keys[] = 'id';
        }

        return (new static(array_intersect_key($this->toArray(), array_flip($keys))))->useTable($this->guessTable());
    }

    final public function getIndexedData(bool $withTimestamps = false): array
    {
        $data = $this->_indexData ?? $this->toArray();

        if (is_callable($data)) {
            $data = $data($this);
        }

        if (!$withTimestamps) {
            unset($data['created_at'], $data['updated_at']);
        }

        return $data;
    }

    final public function queryfy(string $query, array $options = []): RedisSQLCollection
    {
        try {
            return $this->findMany(
                (new RedisSQLCollection(
                    $this->scoutSearch($query, $options)->getHits()
                ))->setModel($this)->pluck('id')->toArray()
            );
        } catch (Exception) {
            return (new RedisSQLCollection)->setModel($this);
        }
    }

    final public function scoutSearch(string $query, array $options = []): array|SearchResult
    {
        return $this->scout()->search($query, $options);
    }

    final public function indexSearch(string $query, array $conditions = [], array $options = []): RedisSQLCollection
    {
        $rows = $this->queryfy($query, $options);

        if (!empty($conditions) && $rows->isNotEmpty()) {
            foreach ($conditions as $key => $value) {
                $rows = $rows->where($key, $value);
            }
        }

        return $rows;
    }

    final public function is(mixed $value, bool $strict = true, string $field = 'id'): bool
    {
        return $strict ? ($this[$field] ?? null) === $value : ($this[$field] ?? null) == $value;
    }

    final public function includes(string $relation, mixed $value, bool $strict = true): bool
    {
        $singular = RedisSQLUtils::singularize($relation);
        $one = true;

        if ($singular !== $relation) {
            $relation = $singular;
            $one = false;
        }

        /** @var static $relation */
        if ($relation = $this->__get($relation) && $one) {
            return $relation->is($value, $strict);
        }

        /** @var static $record */
        foreach ($relation as $record) {
            if ($record->is($value, $strict)) {
                return true;
            }
        }

        return false;
    }

    final public function isInstanceOf(string $table): bool
    {
        return $this->guessTable() === $table;
    }

    final public function factory(callable $resolver, int $count = 1): RedisSQLFactory
    {
        return new RedisSQLFactory($this, $resolver, $count);
    }

    public static function forTable(string $table): self
    {
        return (new static)->useTable($table);
    }

    public static function forTableMetas(string $table): RedisSQLMetable
    {
        return (new RedisSQLMetable)->useTable($table);
    }

    final public function hydrate(?array $data = null): self
    {
        $data = $data ?? $_POST;

        foreach ($data as $k => $v) {
            if ("true" === $v) {
                $v = true;
            } elseif ("false" === $v) {
                $v = false;
            } elseif ("null" === $v) {
                $v = null;
            }

            $this->_attributes[$k] = $v;
        }

        return $this->save();
    }

    final public function addScope(string $name, callable $callback): self
    {
        RedisSQLCollection::scope($name, $this, $callback);

        return $this;
    }

    /** @var RedisSQLFileCache|\Illuminate\Redis\RedisManager|null  */
    protected $_engine = null;

    /**
     * @return RedisSQLFileCache|\Illuminate\Redis\RedisManager
     */
    public function engine()
    {
        if (!$this->_engine) {
            $this->_engine = $this->guessEngine();
        }

        return $this->_engine;
    }

    /**
     * @return RedisSQLFileCache|\Illuminate\Redis\RedisManager
     */
    protected function guessEngine()
    {
        return app('redis');
    }

    public function __call(string $name, array $arguments): mixed
    {
        $scopes = RedisSQLCollection::$scopes[$this->guessTable()] ?? [];

        if ($scopes[$name] ?? null) {
            return $this->all()->{$name}(...$arguments);
        }

        if (str_starts_with($name, 'get') && strlen($name) > 3) {
            $field = RedisSQLUtils::uncamelize(substr($name, 3));

            return $this->_attributes[$field] ?? $arguments[0] ?? null;
        }

        if (str_starts_with($name, 'set') && strlen($name) > 3) {
            $field = RedisSQLUtils::uncamelize(substr($name, 3));

            $this->_attributes[$field] = $arguments[0];

            return $this;
        }

        $field = $name . '_id';

        if (isset($this->_attributes[$field])) {
            return $this->belongsTo($name);
        }

        if (str_ends_with($name, 's')) {
            if (!isset($this->_attributes[$name])) {
                return $this->hasMany(substr($name, 0, -1));
            }
        }

        if (str_starts_with($name, 'findBy')) {
            $field = RedisSQLUtils::uncamelize(substr($name, 6));

            return $this->where($field, $arguments[0])->first();
        }

        $queryBuilder = new RedisSQLQueryBuilder($this);

        return $queryBuilder->{$name}(...$arguments);
    }
}
