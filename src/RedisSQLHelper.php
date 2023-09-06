<?php

use Morbihanet\RedisSQL\RedisSQL;
use Morbihanet\RedisSQL\RedisSQLFile;
use Morbihanet\RedisSQL\RedisSQLFileCache;
use Morbihanet\RedisSQL\RedisSQLUtils;
use Faker\Factory;
use Faker\Generator;
use Faker\Provider\fr_FR\Address;
use Faker\Provider\fr_FR\Company;
use Faker\Provider\fr_FR\Person;
use Faker\Provider\fr_FR\PhoneNumber;
use Faker\Provider\fr_FR\Text;

if (!function_exists('faker')) {
    function faker(string $locale = 'fr_FR'): Generator
    {
        static $faker;

        if ($faker === null) {
            $faker = Factory::create($locale);
            $faker->addProvider(new Company($faker));
            $faker->addProvider(new PhoneNumber($faker));
            $faker->addProvider(new Person($faker));
            $faker->addProvider(new Address($faker));
            $faker->addProvider(new Text($faker));
        }

        return $faker;
    }
}

if (!function_exists('go')) {
    function go(?string $path = null, array $args = []): string
    {
        $args['path'] = RedisSQLUtils::uncamelize($path);

        return route('redis-sql-admin.crud', $args);
    }
}

if (!function_exists('rsqlasset')) {
    function rsqlasset(string $path): string
    {
        if ($path === 'home') {
            return  route('redis-sql-admin.home');
        }

        $path = __DIR__ . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . $path;

        if (file_exists($path)) {
            $asset = file_get_contents($path);
            $asset = preg_replace('/\s+/', ' ', $asset);
            $asset = str_replace('> <', '><', $asset);
            $asset = str_replace(' >', '>', $asset);
            $asset = str_replace('< ', '<', $asset);
            $asset = str_replace(' />', '/>', $asset);
            $asset = str_replace(' <', '<', $asset);
            $asset = str_replace('/> ', '/>', $asset);
            $asset = str_replace(' />', '/>', $asset);
            $asset = str_replace('  ', ' ', $asset);
            $asset = str_replace('  ', ' ', $asset);
            $asset = str_replace('  ', ' ', $asset);
            $asset = str_replace('  ', ' ', $asset);
            $asset = str_replace('  ', ' ', $asset);
            $asset = str_replace('  ', ' ', $asset);
            $asset = str_replace('  ', ' ', $asset);
            $asset = str_replace('  ', ' ', $asset);
            $asset = str_replace('  ', ' ', $asset);
            $asset = str_replace('  ', ' ', $asset);

            return $asset;
        }

        return '';
    }
}

if (!function_exists('khit')) {
    function khit(string $namespace = 'core'): RedisSQLFileCache
    {
        static $khs = [];

        if (!isset($khs[$namespace])) {
            $khs[$namespace] = new RedisSQLFileCache($namespace);
        }

        return $khs[$namespace];
    }
}

if (!function_exists('table')) {
    function table(string $table): RedisSQL
    {
        return RedisSQL::forTable($table);
    }
}

if (!function_exists('table_file')) {
    function table_file(string $table): RedisSQL
    {
        return RedisSQLFile::forTable($table);
    }
}
