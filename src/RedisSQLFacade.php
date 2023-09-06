<?php

namespace Morbihanet\RedisSQL;

/**
 * @mixin RedisSQL
 */
class RedisSQLFacade
{
    public static function __callStatic($method, $args): mixed
    {
        return RedisSQL::forTable(RedisSQLUtils::uncamelize(basename(str_replace('\\', '_', static::class))))
            ->$method(...$args);
    }
}
