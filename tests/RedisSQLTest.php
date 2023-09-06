<?php
namespace Tests;

use Morbihanet\RedisSQL\RedisSQL;
use Morbihanet\RedisSQL\RedisSQLCollection;
use Morbihanet\RedisSQL\RedisSQLFactory;
use Morbihanet\RedisSQL\RedisSQLFile;

class RedisSQLTest extends TestCase
{
    public function test_redissql()
    {
        $start = microtime(true);
        $book = RedisSQL::forTable('book');
        $author = RedisSQL::forTable('author');

        $book->addScope('fleurs', function (RedisSQLCollection $query) {
            return $query->where('title', 'LIKE', '%fleurs%');
        });

        $book->drop();
        $author->drop();

        $this->assertEquals(0, $book->count());
        $this->assertEquals(0, $author->count());

        $baudelaire = $author->create(['name' => 'Charles Baudelaire']);
        $this->assertEquals(1, $author->count());

        $fleursdumal = $book->create(['title' => 'Les Fleurs du mal', 'author_id' => $baudelaire->id]);
        $this->assertEquals(1, $book->count());
        $this->assertEquals(1, $baudelaire->books->count());
        $this->assertEquals(1, $fleursdumal->author->id);

        $victorHugo = $author->firstOrCreate(['name' => 'Victor Hugo']);
        $this->assertEquals(2, $author->count());

        $lesmiserables = $book->firstOrCreate(['title' => 'Les Misérables', 'author_id' => $victorHugo->id]);
        $this->assertEquals(2, $book->count());
        $fresh = $lesmiserables->fresh();

        $this->assertEquals(1, $book->fleurs()->count());

        $this->assertEquals($lesmiserables->id, $fresh->id);
        $this->assertEquals(1, $victorHugo->books->count());
        $this->assertEquals(1, $victorHugo->books()->count());
        $this->assertEquals(2, $lesmiserables->author->id);
        $this->assertEquals(2, $lesmiserables->author()->id);
        $this->assertTrue($lesmiserables->isInstanceOf('book'));
        $this->assertTrue($lesmiserables->author()->isInstanceOf('author'));
        $this->assertTrue($lesmiserables->author()->isInstanceOf($lesmiserables->author()->guessTable()));
        $this->assertEquals($victorHugo->fresh(), $lesmiserables->author());

        $this->assertEquals(1, $book->min('id'));
        $this->assertEquals(2, $book->max('id'));
        $this->assertEquals(1.5, $book->avg('id'));

        $this->assertEquals(2, $book->fulltext('mis')->first()->id);
        $this->assertEquals(2, $book->in('id', [1,2])->count());

        $this->assertTrue($book->find(1)->delete());
        $this->assertEquals(1, $book->count());

        $factory = $book->factory(function () {
           return [
               'title'      => faker()->sentence,
               'author_id'  => rand(1, 2),
               'price'      => faker()->randomFloat(2, 0, 100),
           ];
        });

        $newBook = $factory->create();

        $this->assertEquals(2, $newBook->count());
        $this->assertEquals(3, $newBook->id);

        $this->assertInstanceOf(RedisSQLFactory::class, $factory);

        $book->drop();
        $this->assertEquals(2, $author->count());
        $author->drop();

        $this->assertEquals(0, $book->count());
        $this->assertEquals(0, $author->count());

        dump('redissql', microtime(true) - $start);
    }

    public function test_redissqlfile()
    {
        $start = microtime(true);
        $book = RedisSQLFile::forTable('book');
        $author = RedisSQLFile::forTable('author');

        $book->addScope('fleurs', function (RedisSQLCollection $query) {
            return $query->where('title', 'LIKE', '%fleurs%');
        });

        $book->drop();
        $author->drop();

        $this->assertEquals(0, $book->count());
        $this->assertEquals(0, $author->count());

        $baudelaire = $author->create(['name' => 'Charles Baudelaire']);
        $this->assertEquals(1, $author->count());

        $fleursdumal = $book->create(['title' => 'Les Fleurs du mal', 'author_id' => $baudelaire->id]);
        $this->assertEquals(1, $book->count());
        $this->assertEquals(1, $baudelaire->books->count());
        $this->assertEquals(1, $baudelaire->books()->count());
        $this->assertEquals(1, $fleursdumal->author->id);

        $victorHugo = $author->firstOrCreate(['name' => 'Victor Hugo']);
        $this->assertEquals(2, $author->count());

        $lesmiserables = $book->firstOrCreate(['title' => 'Les Misérables', 'author_id' => $victorHugo->id]);
        $this->assertEquals(2, $book->count());
        $fresh = $lesmiserables->fresh();

        $this->assertEquals(1, $book->fleurs()->count());

        $this->assertEquals($lesmiserables->id, $fresh->id);
        $this->assertEquals(1, $victorHugo->books->count());
        $this->assertEquals(1, $victorHugo->books()->count());
        $this->assertEquals(2, $lesmiserables->author->id);
        $this->assertEquals(2, $lesmiserables->author()->id);
        $this->assertTrue($lesmiserables->isInstanceOf('book'));
        $this->assertTrue($lesmiserables->author()->isInstanceOf('author'));
        $this->assertTrue($lesmiserables->author()->isInstanceOf($lesmiserables->author()->guessTable()));
        $this->assertEquals($victorHugo->fresh(), $lesmiserables->author());

        $this->assertEquals(1, $book->min('id'));
        $this->assertEquals(2, $book->max('id'));
        $this->assertEquals(1.5, $book->avg('id'));

        $this->assertEquals(2, $book->fulltext('mis')->first()->id);
        $this->assertEquals(2, $book->in('id', [1,2])->count());

        $this->assertTrue($book->find(1)->delete());
        $this->assertEquals(1, $book->count());

        $factory = $book->factory(function () {
           return [
               'title'      => faker()->sentence,
               'author_id'  => rand(1, 2),
               'price'      => faker()->randomFloat(2, 0, 100),
           ];
        });

        $newBook = $factory->create();

        $this->assertEquals(2, $newBook->count());
        $this->assertEquals(3, $newBook->id);

        $this->assertInstanceOf(RedisSQLFactory::class, $factory);

        $book->drop();
        $this->assertEquals(2, $author->count());
        $author->drop();

        $this->assertEquals(0, $book->count());
        $this->assertEquals(0, $author->count());

        $book->engine()->destroyDirectory();
        $author->engine()->destroyDirectory();

        dump('redissqlfile', microtime(true) - $start);
    }
}
