<?php

use App\Models\Bookmark;
use App\Models\Collection;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Embeddings;
use Laravel\Sanctum\Sanctum;

test('unauthenticated request returns 401', function () {
    $this->getJson('/api/v1/bookmarks')
        ->assertUnauthorized();
});

test('can create a bookmark with valid url', function () {
    Queue::fake();

    Sanctum::actingAs(User::factory()->create());

    $this->postJson('/api/v1/bookmarks', [
        'url' => 'https://example.com/article',
    ])
        ->assertCreated()
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.domain', 'example.com')
        ->assertJsonPath('data.url', 'https://example.com/article');
});

test('cannot create a bookmark without url', function () {
    Queue::fake();

    Sanctum::actingAs(User::factory()->create());

    $this->postJson('/api/v1/bookmarks', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('url');
});

test('cannot create a bookmark with invalid url', function () {
    Queue::fake();

    Sanctum::actingAs(User::factory()->create());

    $this->postJson('/api/v1/bookmarks', [
        'url' => 'not-a-url',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('url');
});

test('can list bookmarks', function () {
    $user = User::factory()->create();
    Bookmark::factory()->count(3)->for($user)->create();

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/bookmarks')
        ->assertOk()
        ->assertJsonCount(3, 'data');
});

test('list only returns own bookmarks', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    Bookmark::factory()->count(2)->for($user)->create();
    Bookmark::factory()->count(3)->for($otherUser)->create();

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/bookmarks')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

test('can show a single bookmark', function () {
    $user = User::factory()->create();
    $bookmark = Bookmark::factory()->for($user)->create();

    Sanctum::actingAs($user);

    $this->getJson("/api/v1/bookmarks/{$bookmark->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $bookmark->id);
});

test('cannot show another users bookmark', function () {
    $otherUser = User::factory()->create();
    $bookmark = Bookmark::factory()->for($otherUser)->create();

    Sanctum::actingAs(User::factory()->create());

    $this->getJson("/api/v1/bookmarks/{$bookmark->id}")
        ->assertNotFound();
});

test('can soft delete a bookmark', function () {
    $user = User::factory()->create();
    $bookmark = Bookmark::factory()->for($user)->create();

    Sanctum::actingAs($user);

    $this->deleteJson("/api/v1/bookmarks/{$bookmark->id}")
        ->assertNoContent();

    $this->assertSoftDeleted('bookmarks', ['id' => $bookmark->id]);
});

test('deleted bookmark does not appear in list', function () {
    $user = User::factory()->create();
    $bookmark = Bookmark::factory()->for($user)->create();
    $bookmark->delete();

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/bookmarks')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

test('can search bookmarks semantically', function () {
    Embeddings::fake([[array_fill(0, 1536, 0.1)]]);

    $user = User::factory()->create();
    Bookmark::factory()->for($user)->processed()->create(['title' => 'Laravel Framework']);

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/bookmarks?q=php+framework')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'Laravel Framework');
});

test('search returns bookmarks without embeddings via keyword match', function () {
    Embeddings::fake([[array_fill(0, 1536, 0.1)]]);

    $user = User::factory()->create();
    Bookmark::factory()->for($user)->create([
        'title' => 'No Embedding',
        'status' => 'processed',
        'description' => 'Useful saved page',
        'extracted_text' => 'No Embedding is searchable',
        'markdown_text' => 'No Embedding is searchable',
        'embedding' => null,
    ]);

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/bookmarks?q=No+Embedding')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'No Embedding');
});

test('search de-duplicates bookmarks that match keyword and semantic search', function () {
    Embeddings::fake([[array_fill(0, 1536, 0.1)]]);

    $user = User::factory()->create();
    Bookmark::factory()->for($user)->processed()->create([
        'title' => 'Laravel Framework',
        'description' => 'Laravel notes',
        'extracted_text' => 'Laravel framework guide',
        'markdown_text' => 'Laravel framework guide',
    ]);

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/bookmarks?q=Laravel')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'Laravel Framework');
});

test('search ranks keyword matches ahead of semantic only matches', function () {
    Embeddings::fake([[array_fill(0, 1536, 0.1)]]);

    $user = User::factory()->create();
    Bookmark::factory()->for($user)->create([
        'status' => 'processed',
        'title' => 'Knownterm Result',
        'description' => 'Exact keyword result',
        'extracted_text' => 'Knownterm appears here',
        'markdown_text' => 'Knownterm appears here',
        'embedding' => null,
    ]);
    Bookmark::factory()->for($user)->processed()->create([
        'title' => 'Semantic Result',
        'description' => 'Different text',
        'extracted_text' => 'Nothing related',
        'markdown_text' => 'Nothing related',
        'ai_summary' => 'Semantic only item',
    ]);

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/bookmarks?q=Knownterm')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.title', 'Knownterm Result')
        ->assertJsonPath('data.1.title', 'Semantic Result');
});

test('search and tag filter stack together', function () {
    Embeddings::fake([[array_fill(0, 1536, 0.1)]]);

    $user = User::factory()->create();
    $bookmarkA = Bookmark::factory()->for($user)->create([
        'status' => 'processed',
        'title' => 'Tagged Search Result',
        'description' => 'Keyword filter match',
        'extracted_text' => 'Tagged Search Result',
        'markdown_text' => 'Tagged Search Result',
        'embedding' => null,
    ]);
    Bookmark::factory()->for($user)->create([
        'status' => 'processed',
        'title' => 'Untagged Search Result',
        'description' => 'Keyword filter match',
        'extracted_text' => 'Tagged Search Result',
        'markdown_text' => 'Tagged Search Result',
        'embedding' => null,
    ]);

    $tag = Tag::create(['name' => 'laravel', 'slug' => 'laravel']);
    $bookmarkA->tags()->attach($tag->id);

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/bookmarks?q=Tagged+Search+Result&tag=laravel')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'Tagged Search Result');
});

test('search and collection filter stack together', function () {
    Embeddings::fake([[array_fill(0, 1536, 0.1)]]);

    $user = User::factory()->create();
    $collection = Collection::factory()->for($user)->create(['slug' => 'research']);

    $inCollection = Bookmark::factory()->for($user)->processed()->create([
        'title' => 'In Collection',
        'description' => 'Different text',
        'extracted_text' => 'Nothing related',
        'markdown_text' => 'Nothing related',
        'ai_summary' => 'Semantic only item',
    ]);
    $inCollection->collections()->attach($collection->id);

    Bookmark::factory()->for($user)->create([
        'status' => 'processed',
        'title' => 'Research Keyword Match',
        'description' => 'Keyword only result',
        'extracted_text' => 'Research Keyword Match',
        'markdown_text' => 'Research Keyword Match',
        'embedding' => null,
    ]);

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/bookmarks?q=Research+Keyword+Match&collection=research')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'In Collection');
});

test('search validates query max length', function () {
    Sanctum::actingAs(User::factory()->create());

    $this->getJson('/api/v1/bookmarks?q='.str_repeat('a', 501))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('q');
});

test('empty q parameter is ignored', function () {
    $user = User::factory()->create();
    Bookmark::factory()->count(3)->for($user)->processed()->create();

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/bookmarks?q=')
        ->assertOk()
        ->assertJsonCount(3, 'data');
});
