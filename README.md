<h1>Redis ORM abstraction for the Laravel Framework</h1>

```php
use Morbihanet\RedisSQL\RedisSQL;

$bookTable = RedisSQL::forTable('book');
$authorTable = RedisSQL::forTable('author');

$author = $authorTable->firstOrCreate([
    'name' => 'John Doe'
]);

$bookTable->firstOrCreate([
    'title' => 'My Book',
    'author_id' => $author->id,
    'year' => 2020,
    'price' => 10.99
]);

$first = $bookTable->first();
$price = $first->price;
$first->price = 12.99;
$first->save();

// relations
$authorFirst = $first->author;
$books = $authorFirst->books;

// queries
$books = $bookTable->where('year', '>', 2010)->where('price', '<', 20);
$count = $books->count();

// aggregates
$avg = $bookTable->avg('price');
$sum = $bookTable->sum('price');
$max = $bookTable->max('price');
$min = $bookTable->min('price');

// scopes
$bookTable->addScope('year', function($query, $year) {
    return $query->where('year', $year);
});

$books = $bookTable->year(2020);

// helpers
$latest = $bookTable->latest();
$oldest = $bookTable->oldest();
```
