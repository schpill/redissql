<h1>Redis ORM abstraction for Laravel Framework</h1>
<h2>Installation</h2>

```bash
composer require schpill/redissql
```
Add service provider to your config/app.php file:
```php
'providers' => [
    ...
    Morbihanet\RedisSQL\RedisSQLServiceProvider::class,
    ...
]
```

<p style="margin-top: 50px; color: #ffcc00; font-weight: bold;"><u>No need to create migrations nor models</u></p>

<h2>Some examples</h2>

```php
use Morbihanet\RedisSQL\RedisSQL;

$bookModel = RedisSQL::forTable('book');
$authorModel = RedisSQL::forTable('author');

// create
$author = $authorModel->create([
    'name' => 'John Doe'
]);

// firstOrCreate
$bookModel->firstOrCreate([
    'title' => 'My Book',
    'author_id' => $author->id,
    'year' => 2020,
    'price' => 10.99
]);

$first = $bookModel->first();
$price = $first->price;
// or
$price = $first['price'];
// or
$price = $first->>getPrice();

$first->price = 12.99;
// or
$first['price'] = 12.99;
// or
$first->setPrice(12.99);

$first->save();

// relationships
$authorFirst = $first->author;
$books = $authorFirst->books;

// queries
$books = $bookModel->where('year', '>', 2010)->where('price', '<', 20)->orderByDesc('price');
$count = $books->count();

// scopes
$bookModel->addScope('forYear', function($query, $year) {
    return $query->where('year', $year);
});

$books = $bookModel->forYear(2020)->where('price', '<', 20)->orderByDesc('price');

// aggregates
$avg = $bookModel->avg('price');
$sum = $bookModel->sum('price');
$max = $bookModel->max('price');
$min = $bookModel->min('price');

// helpers
$latest = $bookModel->latest();
$oldest = $bookModel->oldest();

$book = $bookModel->find(1);
$book = $bookModel->findOrFail(1);
$book = $bookModel->first();
$status = $book->delete();

// index
$bookModel->index();

// search indexed data
$books = $bookModel->queryfy('My Book');

// unindex
$bookModel->unindex();

// pivots
$tagModel = RedisSQL::forTable('tag');
$firstTag = $tagModel->firstOrCreate(['name' => 'first']);
$book = $bookModel->first();

$book->attach($firstTag);
// or
$book->sync($firstTag);

$tags = $book->tags;

$book->detach($firstTag);
```
