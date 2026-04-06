<?php

use App\Models\Bookmark;
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

test('search excludes bookmarks without embeddings', function () {
    Embeddings::fake([[array_fill(0, 1536, 0.1)]]);

    $user = User::factory()->create();
    Bookmark::factory()->for($user)->processed()->create(['title' => 'Has Embedding']);
    Bookmark::factory()->for($user)->create(['title' => 'No Embedding', 'status' => 'processed', 'embedding' => null]);

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/bookmarks?q=something')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'Has Embedding');
});

test('search and tag filter stack together', function () {
    Embeddings::fake([[array_fill(0, 1536, 0.1)]]);

    $user = User::factory()->create();
    $bookmarkA = Bookmark::factory()->for($user)->processed()->create(['title' => 'Laravel Article']);
    $bookmarkB = Bookmark::factory()->for($user)->processed()->create(['title' => 'PHP Article']);

    $tag = Tag::create(['name' => 'laravel', 'slug' => 'laravel']);
    $bookmarkA->tags()->attach($tag->id);

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/bookmarks?q=php+framework&tag=laravel')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'Laravel Article');
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
