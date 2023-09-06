<?php

namespace Morbihanet\RedisSQL;

class RedisSQLMetable extends RedisSQL
{
    public function metas(): RedisSQLCollection
    {
        return static::forTable('meta')->where('metable_id', $this->id)
            ->where('metable_type', $this->guessTable());
    }

    public function meta(string $name, mixed $default = null): mixed
    {
        $value = value($default);

        if ($row = $this->metas()->firstWhere('name', $name)) {
            $value = unserialize($row->value);
        }

        return $value;
    }

    public function fillMetas(array $metas): void
    {
        foreach ($metas as $name => $value) {
            $value = serialize($value);

            /** @var RedisSQL $row */
            if ($row = $this->metas()->firstWhere('name', $name)) {
                $row->update(compact('value'));
            } else {
                static::forTable('meta')->create(compact('name', 'value'));
            }
        }
    }

    public function deleteMeta(string $name): bool
    {
        if ($row = $this->metas()->firstWhere('name', $name)) {
            $row->delete();

            return true;
        }

        return false;
    }

    public function hasMeta(string $name): bool
    {
        if ($this->metas()->firstWhere('name', $name)) {
            return true;
        }

        return false;
    }

    public function allMetas(): array
    {
        return $this->metas()->pluck('value', 'name')->map(function ($meta) {
            $meta['value'] = unserialize($meta['value']);

            return $meta;
        })->toArray();
    }
}
