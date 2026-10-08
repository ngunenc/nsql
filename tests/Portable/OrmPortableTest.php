<?php

namespace Tests\Portable;

use nsql\database\Config;
use nsql\database\orm\Model;
use nsql\database\orm\ModelNotFoundException;
use nsql\database\orm\StaleModelException;
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

/** Olay kancaları ve optimistic locking (#113) */
class Article extends Model
{
    protected string $table = 'p_articles';
    protected array $fillable = ['title', 'slug', 'body'];
    protected bool $timestamps = false;
    protected bool $soft_deletes = true;
    protected ?string $lock_version_column = 'lock_version';

    /** @var list<string> */
    public array $events = [];
    public bool $block_delete = false;

    protected function on_saving(): ?bool
    {
        $this->events[] = 'saving';
        // Kanca attribute değiştirebilir
        $this->set_attribute('slug', strtolower(str_replace(' ', '-', (string) $this->get_attribute('title'))));

        return $this->get_attribute('title') === 'iptal' ? false : null;
    }

    protected function on_saved(): void
    {
        $this->events[] = 'saved';
    }

    protected function on_creating(): ?bool
    {
        $this->events[] = 'creating';

        return null;
    }

    protected function on_created(): void
    {
        $this->events[] = 'created';
    }

    protected function on_updating(): ?bool
    {
        $this->events[] = 'updating';

        return null;
    }

    protected function on_updated(): void
    {
        $this->events[] = 'updated';
    }

    protected function on_deleting(): ?bool
    {
        $this->events[] = 'deleting';

        return $this->block_delete ? false : null;
    }

    protected function on_deleted(): void
    {
        $this->events[] = 'deleted';
    }
}

/** Elle atanmış (UUID / doğal) birincil anahtarlı model (#88) */
class Voucher extends Model
{
    protected string $table = 'p_vouchers';
    protected string $primary_key = 'code';
    protected array $fillable = ['code', 'label', 'amount'];
    protected array $casts = ['amount' => 'decimal:2'];
    protected bool $timestamps = false;
    protected ?bool $track_exists = true;
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
        $this->create_table('p_articles', [
            'title VARCHAR(100) NOT NULL',
            'slug VARCHAR(100) NULL',
            'body TEXT NULL',
            'lock_version INT NULL',
            'deleted_at TIMESTAMP NULL',
        ]);
        $this->db->query('DROP TABLE IF EXISTS p_vouchers');
        $this->db->query('CREATE TABLE p_vouchers (code VARCHAR(36) PRIMARY KEY, label VARCHAR(50) NOT NULL, amount DECIMAL(15,2) NULL)');
    }

    /**
     * @return list<string> Callback süresince çalışan sorgular
     */
    private function capture_queries(callable $fn): array
    {
        $queries = [];
        $listener = function ($event) use (&$queries): void {
            $queries[] = $event->sql;
        };
        $this->db->on_query($listener);
        try {
            $fn();
        } finally {
            (fn () => $this->query_listeners = array_values(array_filter(
                $this->query_listeners,
                fn ($l) => $l !== $listener
            )))->call($this->db);
        }

        return $queries;
    }

    public function test_dirty_tracking_updates_only_changed_columns(): void
    {
        $id = $this->author()->get_key();
        $author = Author::find_or_fail($id, $this->db);

        $this->assertTrue($author->exists());
        $this->assertFalse($author->is_dirty());

        $author->score = 42;  // veritabanındaki '42' ile aynı: değişiklik sayılmaz
        $this->assertFalse($author->is_dirty('score'));
        $this->assertSame([], $this->capture_queries(fn () => $this->assertTrue($author->save())));

        $author->name = 'Fatma';
        $this->assertSame(['name'], array_keys($author->get_dirty()));
        $queries = $this->capture_queries(fn () => $this->assertTrue($author->save()));

        $this->assertCount(1, $queries);
        $this->assertStringContainsString('name', $queries[0]);
        $this->assertStringContainsString('updated_at', $queries[0]);
        $this->assertStringNotContainsString('score', $queries[0]);
        $this->assertStringNotContainsString('settings', $queries[0]);
        $this->assertFalse($author->is_dirty());
        $this->assertSame('Fatma', Author::find_or_fail($id, $this->db)->name);
    }

    public function test_assigned_key_model_is_inserted_and_tracked(): void
    {
        $voucher = new Voucher($this->db, ['code' => 'c5b1e2f0-0001', 'label' => 'Hoş geldin', 'amount' => '10.005']);
        $this->assertFalse($voucher->exists());
        $this->assertTrue($voucher->save());
        $this->assertTrue($voucher->exists());
        $this->assertSame('c5b1e2f0-0001', $voucher->get_key());

        $loaded = Voucher::find_or_fail('c5b1e2f0-0001', $this->db);
        $this->assertSame('Hoş geldin', $loaded->label);
        $this->assertSame('10.01', $loaded->amount);

        $loaded->label = 'Yeni';
        $this->assertTrue($loaded->save());
        $this->assertSame(1, $this->db->table('p_vouchers')->count());

        $this->assertTrue($loaded->force_delete());
        $this->assertFalse($loaded->exists());
        $this->assertTrue($loaded->save());
        $this->assertSame('Yeni', Voucher::find_or_fail('c5b1e2f0-0001', $this->db)->label);
    }

    public function test_unloaded_model_with_key_updates_by_default(): void
    {
        $id = $this->author()->get_key();

        // ORM_TRACK_EXISTS=false (2.x varsayılanı): anahtarı dolu yüklenmemiş model UPDATE edilir
        $author = new Author($this->db);
        $author->set_attribute('id', $id)->set_attribute('name', 'Elif');
        $this->assertTrue($author->save());
        $this->assertSame(1, $this->db->table('p_authors')->count());
        $this->assertSame('Elif', Author::find_or_fail($id, $this->db)->name);
    }

    public function test_changed_primary_key_updates_original_row(): void
    {
        (new Voucher($this->db, ['code' => 'old-code', 'label' => 'x']))->save();

        $voucher = Voucher::find_or_fail('old-code', $this->db);
        $voucher->set_attribute('code', 'new-code');
        $this->assertTrue($voucher->save());

        $this->assertNull(Voucher::find('old-code', $this->db));
        $this->assertSame('x', Voucher::find_or_fail('new-code', $this->db)->label);
    }

    public function test_model_event_hooks(): void
    {
        $article = new Article($this->db, ['title' => 'Merhaba Dünya']);
        $this->assertTrue($article->save());
        $this->assertSame(['saving', 'creating', 'created', 'saved'], $article->events);
        $this->assertSame('merhaba-dünya', Article::find_or_fail($article->get_key(), $this->db)->slug);

        $article->events = [];
        $this->assertTrue($article->save());
        $this->assertSame(['saving', 'saved'], $article->events, 'Değişiklik yok: yalnızca saving/saved');

        $article->events = [];
        $article->body = 'içerik';
        $this->assertTrue($article->save());
        $this->assertSame(['saving', 'updating', 'updated', 'saved'], $article->events);

        $cancelled = new Article($this->db, ['title' => 'iptal']);
        $this->assertFalse($cancelled->save());
        $this->assertFalse($cancelled->exists());
        $this->assertSame(1, $this->db->table('p_articles')->count());

        $article->events = [];
        $article->block_delete = true;
        $this->assertFalse($article->delete());
        $this->assertSame(['deleting'], $article->events);
        $this->assertFalse($article->trashed());

        $article->block_delete = false;
        $article->events = [];
        $this->assertTrue($article->delete());
        $this->assertSame(['deleting', 'deleted'], $article->events);
        $this->assertTrue($article->trashed());
    }

    public function test_optimistic_locking(): void
    {
        $article = new Article($this->db, ['title' => 'Sürüm']);
        $article->save();
        $this->assertSame(1, (int) $article->lock_version);

        $first = Article::find_or_fail($article->get_key(), $this->db);
        $second = Article::find_or_fail($article->get_key(), $this->db);

        $first->body = 'ilk';
        $this->assertTrue($first->save());
        $this->assertSame(2, (int) $first->lock_version);
        $this->assertSame(2, (int) Article::find_or_fail($article->get_key(), $this->db)->lock_version);

        $second->body = 'ikinci';
        try {
            $second->save();
            $this->fail('Eski sürümle kayıt StaleModelException vermeli');
        } catch (StaleModelException $e) {
            $this->assertSame(Article::class, $e->model);
            $this->assertSame(1, (int) $e->expected_version);
        }
        $this->assertSame('ilk', Article::find_or_fail($article->get_key(), $this->db)->body);

        // Elle verilen sürüm yok sayılır
        $first->set_attribute('lock_version', 99);
        $first->body = 'üçüncü';
        $first->save();
        $this->assertSame(3, (int) Article::find_or_fail($article->get_key(), $this->db)->lock_version);

        // Soft delete ve kalıcı silme de sürümü kontrol eder
        $this->expectException(StaleModelException::class);
        $second->force_delete();
    }

    public function test_soft_delete_bumps_version(): void
    {
        $article = new Article($this->db, ['title' => 'Silinecek']);
        $article->save();
        $loaded = Article::find_or_fail($article->get_key(), $this->db);

        $this->assertTrue($loaded->delete());
        $this->assertSame(2, (int) $loaded->lock_version);
        $this->assertTrue($loaded->restore());
        $this->assertSame(3, (int) $loaded->lock_version);
        $this->assertTrue($loaded->force_delete());
        $this->assertSame(0, $this->db->table('p_articles')->count());
    }

    public function test_decimal_cast_rounds_without_float_error(): void
    {
        $cases = [
            '10.005' => '10.01',
            '0.1' => '0.10',
            '9.995' => '10.00',
            '-0.004' => '0.00',
            '-2.345' => '-2.35',
            '1234567890123.455' => '1234567890123.46',
            '7' => '7.00',
        ];
        foreach ($cases as $input => $expected) {
            $voucher = new Voucher($this->db, ['amount' => (string) $input]);
            $this->assertSame($expected, $voucher->amount, "decimal:2 ← {$input}");
        }

        $this->assertSame('0.30', (new Voucher($this->db, ['amount' => 0.1 + 0.2]))->amount);
        $this->assertSame('1000.00', (new Voucher($this->db, ['amount' => '1e3']))->amount);

        $this->expectException(\InvalidArgumentException::class);
        new Voucher($this->db, ['amount' => 'abc']);
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

    public function test_eager_loading_uses_one_query_per_relation(): void
    {
        $authors = [$this->author('A'), $this->author('B'), $this->author('C')];
        foreach ($authors as $i => $author) {
            for ($j = 0; $j <= $i; $j++) {
                (new Post($this->db, ['author_id' => $author->get_key(), 'title' => "p{$i}{$j}"]))->save();
            }
        }
        (new Profile($this->db, ['author_id' => $authors[1]->get_key(), 'bio' => 'B bio']))->save();

        $queries = [];
        $this->db->on_query(function (...$args) use (&$queries) {
            $queries[] = $args;
        });

        $posts = Post::get(fn ($q) => $q->order_by('id'), $this->db, with: ['author']);
        $this->assertCount(6, $posts);
        $this->assertCount(2, $queries, 'gönderiler + yazarlar');
        foreach ($posts as $post) {
            $this->assertTrue($post->relation_loaded('author'));
            $this->assertSame((int) $post->author_id, (int) $post->author->get_key());
        }
        $this->assertSame('C', $posts[5]->author->name);
        $this->assertCount(2, $queries, 'erişim ek sorgu çalıştırmaz');

        $queries = [];
        $loaded = Author::get(fn ($q) => $q->order_by('id'), $this->db, with: ['posts', 'profile']);
        $this->assertCount(3, $queries, 'yazarlar + gönderiler + profiller');
        $this->assertSame([1, 2, 3], array_map(fn (Author $a) => count($a->posts), $loaded));
        $this->assertSame(['p20', 'p21', 'p22'], array_map(fn (Post $p) => $p->title, $loaded[2]->posts));
        $this->assertNull($loaded[0]->profile);
        $this->assertSame('B bio', $loaded[1]->profile->bio);
        $this->assertCount(3, $queries);

        $this->db->clear_query_listeners();
    }

    public function test_eager_load_on_existing_models_and_errors(): void
    {
        $author = $this->author();
        $orphan = new Post($this->db);
        $orphan->force_fill(['id' => 99, 'author_id' => 12345, 'title' => 'yetim']);

        Post::eager_load([$orphan], 'author');
        $this->assertTrue($orphan->relation_loaded('author'));
        $this->assertNull($orphan->author);

        $first = Author::first(fn ($q) => $q->where('id', '=', $author->get_key()), $this->db, with: ['posts']);
        $this->assertSame([], $first->posts);
        $this->assertTrue($first->relation_loaded('posts'));

        $this->expectException(\InvalidArgumentException::class);
        Author::eager_load([$author], 'save');
    }

    public function test_table_name_resolution_modes(): void
    {
        $this->assertSame('blog_categories', (new BlogCategory($this->db))->get_table());

        Config::set('orm_table_naming', 'legacy');
        $this->assertSame('blogcategorys', (new BlogCategory($this->db))->get_table());
    }
}
