<?php

namespace Morbihanet\RedisSQL;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Meilisearch\Client as MSClient;
use Meilisearch\Endpoints\Indexes;
use Meilisearch\Meilisearch;

class RedisSQLUtils
{
    public static function uncamelize(string $string, string $splitter = "_"): string
    {
        return strtolower(preg_replace(
            '/(?!^)[[:upper:]][[:lower:]]/',
            '$0',
            preg_replace('/(?!^)[[:upper:]]+/', $splitter . '$0', $string)
        ));
    }

    public static function isJSON(?string $string = null): bool
    {
        if (is_null($string)) {
            return false;
        }

        json_decode($string);

        return json_last_error() === JSON_ERROR_NONE;
    }

    public static function singularize(string $word): string
    {
        $singular = [
            '/(quiz)zes$/i' => "$1",
            '/(matr)ices$/i' => "$1ix",
            '/(vert|ind)ices$/i' => "$1ex",
            '/^(ox)en$/i' => "$1",
            '/(alias)es$/i' => "$1",
            '/(octop|vir)i$/i' => "$1us",
            '/(cris|ax|test)es$/i' => "$1is",
            '/(shoe)s$/i' => "$1",
            '/(o)es$/i' => "$1",
            '/(bus)es$/i' => "$1",
            '/(quizz)es$/i' => "$1",
            '/([m|l])ice$/i' => "$1ouse",
            '/(x|ch|ss|sh)es$/i' => "$1",
            '/(m)ovies$/i' => "$1ovie",
            '/(s)eries$/i' => "$1eries",
            '/([^aeiouy]|qu)ies$/i' => "$1y",
            '/([lr])ves$/i' => "$1f",
            '/(tive)s$/i' => "$1",
            '/(hive)s$/i' => "$1",
            '/(li|wi|kni)ves$/i' => "$1fe",
            '/(shea|loa|lea|thie)ves$/i' => "$1f",
            '/(^analy)ses$/i' => "$1sis",
            '/(ti)a$/i' => "$1um",
            '/(n)ews$/i' => "$1ews",
            '/(h|bl)ouses$/i' => "$1ouse",
            '/(corpse)s$/i' => "$1",
            '/(us)es$/i' => "$1",
            '/s$/i' => "",
        ];

        $uncountable = [
            'equipment',
            'information',
            'rice',
            'news',
            'money',
            'species',
            'series',
            'fish',
            'sheep',
        ];

        if (in_array(mb_strtolower($word), $uncountable)) {
            return $word;
        }

        foreach ($singular as $rule => $replacement) {
            if (preg_match($rule, $word)) {
                return preg_replace($rule, $replacement, $word);
            }
        }

        if (str_ends_with($word, 's')) {
            return substr($word, 0, -1);
        }

        return $word;
    }

    public static function pluralize(string $word): string
    {
        $plural = [
            '/(quiz)$/i' => "$1zes",
            '/^(ox)$/i' => "$1en",
            '/([m|l])ouse$/i' => "$1ice",
            '/(matr|vert|vort|ind)ix|ex$/i' => "$1ices",
            '/(x|ch|ss|sh)$/i' => "$1es",
            '/([^aeiouy]|qu)y$/i' => "$1ies",
            '/(hive)$/i' => "$1s",
            '/(?:([^f])fe|([lr])f)$/i' => "$1$2ves",
            '/(shea|lea|loa|thie)f$/i' => "$1ves",
            '/sis$/i' => "ses",
            '/([ti])um$/i' => "$1a",
            '/(tomat|potat|ech|her|vet)o$/i' => "$1oes",
            '/(bu)s$/i' => "$1ses",
            '/(alias)$/i' => "$1es",
            '/(octop)us$/i' => "$1i",
            '/(ax|test)is$/i' => "$1es",
            '/(us)$/i' => "$1es",
            '/s$/i' => "s",
            '/$/' => "s",
        ];

        $uncountable = [
            'equipment',
            'information',
            'rice',
            'money',
            'species',
            'series',
            'fish',
            'sheep',
        ];

        if (in_array(mb_strtolower($word), $uncountable)) {
            return $word;
        }

        // check for irregular words
        foreach ($plural as $pattern => $result) {
            if (preg_match($pattern, $word)) {
                return preg_replace($pattern, $result, $word);
            }
        }

        return $word . 's';
    }

    public static function meilisearch_client(): MSClient
    {
        return new MSClient(
            config('scout.meilisearch.host'),
            config('scout.meilisearch.key')
        );
    }

    public static function meilisearch_index(string $name, array $options = []): Indexes
    {
        $client = static::meilisearch_client();
        $index = $client->index($name);
        $exists = $index->getCreatedAt() !== null;

        if (!empty($options) && !$exists) {
            $index->updateSettings($options);
        }

        return $index;
    }

    public static function meilisearch_add_document(string|Indexes $index, array $document, array $options = []): Indexes
    {
        return static::meilisearch_add_documents($index, [$document], $options);
    }

    public static function meilisearch_add_documents(string|Indexes $index, array $documents, array $options = []): Indexes
    {
        if (is_string($index)) {
            $index = static::meilisearch_index($index, $options);
        }

        $index->addDocuments($documents, $options['primaryKey'] ?? 'id');

        return $index;
    }

    public static function meilisearch_search(string $indexName, string $query, array $options = []): array
    {
        $index = static::meilisearch_index($indexName);

        if (version_compare(Meilisearch::VERSION, '0.19.0') >= 0 && isset($options['filters'])) {
            $options['filter'] = $options['filters'];

            unset($options['filters']);
        }

        return $index->search($query, $options);
    }

    public static function compare($actual, $operator = null, $value = null): bool
    {
        $nargs = func_num_args();

        if ($nargs === 1) {
            return $actual === true;
        }

        if ($nargs === 2) {
            $value = $operator;

            if (is_array($actual) || is_object($actual)) {
                $actual = serialize($actual);
            }

            if (is_array($value) || is_object($value)) {
                $value = serialize($value);
            }

            return sha1($actual) === sha1($value);
        }

        $strings = array_filter([$actual, $value], function ($concern) {
            return is_string($concern) || (is_object($concern) && method_exists($concern, '__toString'));
        });

        if (count($strings) < 2 && count(array_filter([$actual, $value], 'is_object')) === 1) {
            $status = fnmatch('not *', $operator) ? true : false;

            return $status || in_array($operator, ['!=', '<>', '!==']);
        }

        $operator = Str::lower($operator);

        if (in_array($operator, ['=', '==', '==='])) {
            if (is_array($actual) || is_object($actual)) {
                $actual = serialize($actual);
            }

            if (is_array($value) || is_object($value)) {
                $value = serialize($value);
            }

            return sha1($actual) === sha1($value);
        }

        if (in_array($operator, ['<>', '!=', '!=='])) {
            if (is_array($actual) || is_object($actual)) {
                $actual = serialize($actual);
            }

            if (is_array($value) || is_object($value)) {
                $value = serialize($value);
            }

            return sha1($actual) !== sha1($value);
        }

        switch ($operator) {
            case 'filter':
            case 'custom':
                return $value($actual);
            case 'gt':
            case '>':
                return $actual > $value;
            case 'lt':
            case '<':
                return $actual < $value;
            case 'gte':
            case '>=':
                return $actual >= $value;
            case 'lte':
            case '<=':
                return $actual <= $value;
            case 'between':
                $value = !is_array($value)
                    ? explode(',', str_replace([' ,', ', '], ',', $value))
                    : $value
                ;

                return is_array($value) && $actual >= min($value) && $actual <= max($value);
            case 'not between':
                $value = !is_array($value)
                    ? explode(',', str_replace([' ,', ', '], ',', $value))
                    : $value
                ;

                return is_array($value) && ($actual < min($value) || $actual > max($value));
            case 'in':
                $value = !is_array($value)
                    ? explode(',', str_replace([' ,', ', '], ',', $value))
                    : $value
                ;

                return in_array($actual, $value);
            case 'not in':
                $value = !is_array($value)
                    ? explode(',', str_replace([' ,', ', '], ',', $value))
                    : $value
                ;

                return !in_array($actual, $value);
            case 'like':
            case 'match':
                $value = str_replace(['%', '*'], '.*', $value);

                return preg_match("/" . $value . "$/i", $actual) ? true : false;
            case 'not like':
            case 'not match':
                $value = str_replace(['%', '*'], '.*', $value);

                return preg_match("/" . $value . "$/i", $actual) ? false : true;
            case 'instanceof':
                return $actual instanceof $value;
            case 'not instanceof':
                return !$actual instanceof $value;
            case 'true':
            case true:
                return true === $actual;
            case 'false':
            case false:
                return false === $actual;
            case 'empty':
            case 'null':
            case null:
            case 'is null':
            case 'is':
                return empty($actual);
            case 'not empty':
            case 'not null':
            case 'is not empty':
            case 'is not null':
            case 'is not':
                return !empty($actual);
            case 'regex':
            case 'pattern':
                return preg_match($value, $actual) ? true : false;
            case 'not regex':
            case 'not pattern':
                return !preg_match($value, $actual) ? true : false;
            case 'levenshtein':
            case 'fuzzy':
                return levenshtein($actual, $value) <= mb_strlen($value) / 3;
            case 'not levenshtein':
            case 'not fuzzy':
                return levenshtein($actual, $value) > mb_strlen($value) / 3;
            case 'soundex':
                return soundex($actual) === soundex($value);
            case 'not soundex':
                return soundex($actual) !== soundex($value);
            case 'similar text':
            case 'similar_text':
                return similar_text($actual, $value) > 0;
            case 'not similar text':
            case 'not similar_text':
                return similar_text($actual, $value) === 0;
            case 'hash':
                return password_verify($value, $actual);
            case 'not hash':
                return !password_verify($value, $actual);
            case 'almost':
                return abs($actual - $value) < 0.0000001;
            case 'not almost':
                return abs($actual - $value) >= 0.0000001;
            case 'contains':
                return Str::contains($actual, $value);
            case 'not contains':
                return !Str::contains($actual, $value);
            case 'starts with':
            case 'starts_with':
                return Str::startsWith($actual, $value);
            case 'not starts with':
            case 'not starts_with':
                return !Str::startsWith($actual, $value);
            case 'ends with':
            case 'ends_with':
                return Str::endsWith($actual, $value);
            case 'not ends with':
            case 'not ends_with':
                return !Str::endsWith($actual, $value);
            case 'is json':
                return static::isJSON($actual);
        }

        return false;
    }

    public static function isPost(): bool
    {
        return !empty($_POST ?? []);
    }

    public static function record(array $data, string $table = 'internalrecord'): RedisSQL
    {
        return RedisSQLMemory::forTable($table)->create($data, true);
    }

    public static function records(array|callable $rows, string $table ='internalrecord'): RedisSQLCollection
    {
        if (!is_callable($rows)) {
            $rows = function() use ($rows, $table) {
                foreach ($rows as $row) {
                    yield static::record($row, $table);
                }
            };
        }

        return new RedisSQLCollection($rows);
    }

    public static function getMimeType(string $filename): string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);

        return finfo_file($finfo, $filename);
    }

    public static function paginator($items, $total, $perPage, $currentPage, $options): LengthAwarePaginator
    {
        $options = array_merge(['pageName' => 'page'], $options);

        return app()->makeWith(LengthAwarePaginator::class, compact(
            'items',
            'total',
            'perPage',
            'currentPage',
            'options'
        ));
    }

    static ?string $one = null;

    public static function bearer(?string $name = null): ?string
    {
        if (!static::$one) {
            $name = $name ?? 'app_bearer';

            if (!$cookie = Arr::get($_COOKIE, $name)) {
                $cookie = sha1(uniqid(sha1(uniqid(static::userFingerprint(), true)), true));
            }

            setcookie($name, $cookie, strtotime('+1 year'), '/');

            return static::$one = $cookie;
        }

        return static::$one;
    }

    public static function userFingerprint(): string
    {
        return sha1(
            $_SERVER['HTTP_USER_AGENT'] ?? '' . $_SERVER['REMOTE_ADDR'] ?? '' . $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''
        );
    }

    public static function localDate(?int $ts = null, bool $start = true, string $locale = 'fr_FR')
    {
        date_default_timezone_set(config('app.timezone'));

        $ts ??= time();

        $date = Carbon::createFromTimestamp($ts)->locale($locale);

        return $start ? $date->startOfDay() : $date;
    }

    public static function now(string $locale = 'fr_FR'): Carbon
    {
        return static::localDate(null, $locale, false);
    }

    public static function isPlural(string $name): bool
    {
        return static::singularize($name) !== $name;
    }

    public static function generateAlphanumericID(int $length = 21): string
    {
        $numerics = str_shuffle('0123456789');
        $lowercases = str_shuffle('abcdefghijklmnopqrstuvwxyz');
        $uppercases = str_shuffle('ABCDEFGHIJKLMNOPQRSTUVWXYZ');
        $alls = str_shuffle($numerics . $lowercases . $uppercases);

        $splittedLength = round($length / 3);

        $id = substr(str_shuffle($numerics), 0, $splittedLength);
        $id .= substr(str_shuffle($lowercases), 0, $splittedLength);
        $id .= substr(str_shuffle($uppercases), 0, $splittedLength);

        $strlenId = strlen($id);

        if ($strlenId < $length) {
            $id .= substr(str_shuffle($alls), 0, $length - strlen($id));
        } elseif ($strlenId > $length) {
            $id = substr($id, 0, $length);
        }

        return str_shuffle($id);
    }

    public static function generateAlphanumericIDUnless(callable $callable, int $length = 20): string
    {
        // check if id already exists
        if ($callable($id = static::generateAlphanumericID($length))) {
            return static::generateAlphanumericIDUnless($callable, $length);
        }

        return $id;
    }

    public static function generateAlphanumericIDForModel(
        RedisSQL $model,
        string $column = 'uuid',
        int $length = 20
    ): string {
        return static::generateAlphanumericIDUnless(
            fn($id) => $model->where($column, $id)->isNotEmpty(),
            $length
        );
    }

    public static function generateAlphanumericIDForTable(
        string $table,
        string $column = 'uuid',
        int $length = 20
    ): string {
        return static::generateAlphanumericIDForModel(RedisSQL::forTable($table), $column, $length);
    }

    public static function cache(string $ns = 'core'): RedisSQLStore
    {
        return RedisSQLStore::make($ns);
    }
}
