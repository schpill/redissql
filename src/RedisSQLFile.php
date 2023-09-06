<?php

namespace Morbihanet\RedisSQL;

class RedisSQLFile extends RedisSQL
{
    /**
     * @return RedisSQLFileCache
     */
    protected function guessEngine()
    {
        return new RedisSQLFileCache('redis-sql-file/' . $this->guessTable());
    }
}
