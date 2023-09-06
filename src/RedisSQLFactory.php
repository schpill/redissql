<?php

namespace Morbihanet\RedisSQL;

class RedisSQLFactory
{
    public function __construct(
        public RedisSQL $model,
        protected mixed $resolver,
        protected int $count = 1
    ) {}

    public function make(): RedisSQLCollection
    {
        $rows = [];
        $class = get_class($this->model);

        for ($i = 0; $i < $this->count; ++$i) {
            $rows[] = (new $class(value($this->resolver)))
                ->useTable($this->model->guessTable());
        }

        return (new RedisSQLCollection($rows))->setModel($this->model);
    }

    public function create(): RedisSQLCollection|RedisSQL
    {
        $collection = $this->make()->map(function (RedisSQL $row) {
            return $row->save();
        });

        return  $this->count > 1 ? $collection : $collection->first();
    }
}
