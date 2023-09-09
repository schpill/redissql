<?php

namespace Morbihanet\RedisSQL;

class RedisSQLMemory extends RedisSQL
{
    /**
     * @return RedisSQLMemoryCache
     */
    protected function guessEngine()
    {
        return new RedisSQLMemoryCache('redissqlmemory', $this->guessTable());
    }
}
