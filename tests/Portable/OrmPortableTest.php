<?php

namespace Tests\Portable;

use nsql\database\Config;
use nsql\database\orm\Model;
use nsql\database\orm\ModelNotFoundException;
use Tests\Support\PortableTestCase;

class Author extends Model
{
    protected string $table = 'p_authors';
    protected array $fillable = ['name', 'settings', 'is_active', 'score', 'rating', 'born_at', 'joined_on'];
    protected array $casts = [
        'settings' => 'array',
        'is_active' => 'bool',
        'score' => 'int',
        'rating' => 'float',
        'born_at' => 'datetime',
        'joined_on' => 'date',
    ];

    /** @return list<Post> */
    public function posts(): array
    {
        return $this->has_many(Post::class, scope: fn ($q) => $q->order_by('id'));
    }

    public function profile(): ?Profile
    {
        return $this->has_one(Profile::class);
    }
}

class Post extends Model
{
    protected string $table = 'p_posts';
    protected array $fillable = ['author_id', 'title'];
    protected bool $timestamps = false;
    protected bool $soft_deletes = true;

    public function author(): ?Author
    {
        return $this->belongs_to(Author::class);
    }
}

class Profile extends Model
{
    protected string $table = 'p_profiles';
    protected array $guarded = ['id'];
    protected bool $timestamps = false;
}

class BlogCategory extends Model
{
}

class OrmPortableTest extends PortableTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $bool = self::driver() === 'mysql' ? 'TINYINT(1)' : 'BOOLEAN';
        $this->create_table('p_authors', [
            'name VARCHAR(50) NOT NULL',
            'settings TEXT NULL',
            "is_active {$bool} NULL",
            'score INT NULL',
            'rating DECIMAL(5,2) NULL',
            'born_at TIMESTAMP NULL',
            'joined_on DATE NULL',
            'created_at TIMESTAMP NULL',
            'updated_at TIMESTAMP NULL',
        ]);
        $this->create_table('p_posts', ['author_id INT NOT NULL', 'title VARCHAR(100) NOT NULL', 'deleted_at TIMESTAMP NULL']);
        $this->create_table('p_profiles', ['author_id INT NOT NULL', 'bio VARCHAR(200) NULL']);
    }

    protected function tearDown(): void
    {
        Config::set('orm_table_naming', Config::orm_table_naming);
        parent::tearDown();
    }

    private function author(string $name = 'Ayşe'): Author
    {
        $author = new Author($this->db, [
            'name' => $name,
            'settings' => ['theme' => 'dark', 'tags' => ['a', 'b']],
            'is_active' => true,
            'score' => '42',
            'rating' => 4.5,
            'born_at' => new \DateTimeImmutable('1990-05-06 07:08:09'),
            'joined_on' => '2024-01-02',
        ]);
        $this->assertTrue($author->save());

        return $author;
    }

    public function test_casts_round_trip_through_database(): void
    {
        $id = $this->author()->get_key();

        $author = Author::find_or_fail($id, $this->db);

        $this->assertSame(['theme' => 'dark', 'tags' => ['a', 'b']], $author->settings);
        $this->assertTrue($author->is_active);
        $this->assertSame(42, $author->score);
        $this->assertSame(4.5, $author->rating);
        $this->assertInstanceOf(\DateTimeImmutable::class, $author->born_at);
        $this->assertSame('1990-05-06 07:08:09', $author->born_at->format('Y-m-d H:i:s'));
        $this->assertSame('2024-01-02', $author->joined_on->format('Y-m-d'));

        $array = $author->to_array();
        $this->assertSame('1990-05-06 07:08:09', $array['born_at']);
        $this->assertSame('2024-01-02', $array['joined_on']);
        $this->assertSame(['theme' => 'dark', 'tags' => ['a', 'b']], $array['settings']);
        $this->assertSame('dark', json_decode((string) json_encode($author), true)['settings']['theme']);
    }

    public function test_false_and_null_casts(): void
    {
        $author = new Author($this->db, ['name' => 'Can', 'is_active' => false, 'settings' => null]);
        $author->save();

        $loaded = Author::find_or_fail($author->get_key(), $this->db);
        $this->assertFalse($loaded->is_active);
        $this->assertNull($loaded->settings);
        $this->assertNull($loaded->born_at);
    }

    public function test_has_many_belongs_to_and_has_one(): void
    {
        $author = $this->author();
        $other = $this->author('Mehmet');
        foreach (['ilk', 'ikinci'] as $title) {
            (new Post($this->db, ['author_id' => $author->get_key(), 'title' => $title]))->save();
        }
        (new Post($this->db, ['author_id' => $other->get_key(), 'title' => 'başka']))->save();
        (new Profile($this->db, ['author_id' => $author->get_key(), 'bio' => 'yazar']))->save();

        $posts = $author->posts;
        $this->assertCount(2, $posts);
        $this->assertContainsOnlyInstancesOf(Post::class, $posts);
        $this->assertSame(['ilk', 'ikinci'], array_map(fn (Post $p) => $p->title, $posts));
        $this->assertTrue($author->relation_loaded('posts'));

        $owner = $posts[0]->author;
        $this->assertInstanceOf(Author::class, $owner);
        $this->assertSame('Ayşe', $owner->name);

        $this->assertSame('yazar', $author->profile->bio);
        $this->assertNull($other->profile);

        $array = $author->to_array();
        $this->assertSame('ikinci', $array['posts'][1]['title']);
        $this->assertSame('yazar', $array['profile']['bio']);
    }

    public function test_relations_are_cached_until_reloaded(): void
    {
        $author = $this->author();
        $this->assertSame([], $author->posts);

        (new Post($this->db, ['author_id' => $author->get_key(), 'title' => 'yeni']))->save();
        $this->assertSame([], $author->posts);

        $author->load('posts');
        $this->assertCount(1, $author->posts);

        $this->expectException(\InvalidArgumentException::class);
        $author->load('save');
    }

    public function test_soft_delete_restore_and_force_delete(): void
    {
        $author = $this->author();
        $post = new Post($this->db, ['author_id' => $author->get_key(), 'title' => 'silinecek']);
        $post->save();
        $id = $post->get_key();

        $this->assertTrue($post->delete());
        $this->assertTrue($post->trashed());
        $this->assertNull(Post::find($id, $this->db));
        $this->assertSame([], Post::get(null, $this->db));
        $this->assertSame(1, (new Post($this->db))->query_with_trashed()->count());

        $this->assertTrue($post->restore());
        $this->assertFalse($post->trashed());
        $this->assertNotNull(Post::find($id, $this->db));

        $this->assertTrue($post->force_delete());
        $this->assertSame(0, (new Post($this->db))->query_with_trashed()->count());
    }

    public function test_static_query_helpers(): void
    {
        $this->author('A');
        $this->author('B');
        $this->author('C');

        $names = array_map(fn (Author $a) => $a->name, Author::get(fn ($q) => $q->where('name', '!=', 'B')->order_by('name', 'DESC'), $this->db));
        $this->assertSame(['C', 'A'], $names);

        $this->assertSame('B', Author::first(fn ($q) => $q->where('name', '=', 'B'), $this->db)?->name);
        $this->assertNull(Author::first(fn ($q) => $q->where('name', '=', 'Z'), $this->db));

        $hydrated = Author::hydrate([['id' => 9, 'score' => '7']], $this->db);
        $this->assertSame(7, $hydrated[0]->score);

        $this->expectException(ModelNotFoundException::class);
        Author::find_or_fail(999, $this->db);
    }

    public function test_guarded_allows_everything_except_listed(): void
    {
        $profile = new Profile($this->db, ['id' => 5, 'author_id' => 1, 'bio' => 'x']);

        $this->assertNull($profile->id);
        $this->assertSame(1, (int) $profile->author_id);
        $this->assertTrue($profile->is_fillable('anything'));
        $this->assertFalse($profile->is_fillable('id'));
    }

    public function test_table_name_resolution_modes(): void
    {
        $this->assertSame('blog_categories', (new BlogCategory($this->db))->get_table());

        Config::set('orm_table_naming', 'legacy');
        $this->assertSame('blogcategorys', (new BlogCategory($this->db))->get_table());
    }
}
