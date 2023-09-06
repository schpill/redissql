<?php

namespace Morbihanet\RedisSQL;

trait RedisSQLTrait
{
    protected ?string $_entity = null;

    public function __call($method, $args): mixed
    {
        return RedisSQL::forTable($this->_entity)->$method(...$args);
    }

    public static function __callStatic($method, $args): mixed
    {
        $instance = new static;

        return RedisSQL::forTable($instance->getInternalEntity())->$method(...$args);
    }

    public function getInternalEntity(): ?string
    {
        return $this->_entity;
    }

    public function setInternalEntity(string $entity): static
    {
        $this->_entity = $entity;

        return $this;
    }
}
