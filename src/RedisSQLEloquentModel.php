<?php

namespace Morbihanet\RedisSQL;

use Illuminate\Database\Eloquent\Model;

class RedisSQLEloquentModel extends Model
{
    use RedisSQLTrait;

    protected $guarded = [];
}
