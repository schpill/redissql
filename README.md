<h1>Redis ORM abstraction for the Laravel Framework</h1>

```php
use Morbihanet\RedisSQL\RedisSQL;

$bookTable = RedisSQL::forTable('book');
$authorTable = RedisSQL::forTable('author');

// create
$author = $authorTable->create([
    'name' => 'John Doe'
]);

// firstOrCreate
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
$books = $bookTable->where('year', '>', 2010)->where('price', '<', 20)->orderByDesc('price');
$count = $books->count();

// aggregates
$avg = $bookTable->avg('price');
$sum = $bookTable->sum('price');
$max = $bookTable->max('price');
$min = $bookTable->min('price');

// scopes
$bookTable->addScope('forYear', function($query, $year) {
    return $query->where('year', $year);
});

$books = $bookTable->forYear(2020);

// helpers
$latest = $bookTable->latest();
$oldest = $bookTable->oldest();
```
