<?php

use App\Models\Bookmark;
use App\Models\Tag;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

test('can list tags with bookmark counts', function () {
    $user = User::factory()->create();
    $bookmark = Bookmark::factory()->for($user)->processed()->create();

    $tagA = Tag::create(['name' => 'laravel', 'slug' => 'laravel']);
    $tagB = Tag::create(['name' => 'php', 'slug' => 'php']);
    $bookmark->tags()->attach([$tagA->id, $tagB->id]);

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/tags')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.name', 'laravel')
        ->assertJsonPath('data.0.bookmarks_count', 1)
        ->assertJsonPath('data.1.name', 'php');
});

test('tags are ordered alphabetically', function () {
    $user = User::factory()->create();
    $bookmark = Bookmark::factory()->for($user)->processed()->create();

    $tagB = Tag::create(['name' => 'python', 'slug' => 'python']);
    $tagA = Tag::create(['name' => 'laravel', 'slug' => 'laravel']);
    $bookmark->tags()->attach([$tagA->id, $tagB->id]);

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/tags')
        ->assertOk()
        ->assertJsonPath('data.0.name', 'laravel')
        ->assertJsonPath('data.1.name', 'python');
});

test('tags from other users bookmarks are not returned', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();

    $otherBookmark = Bookmark::factory()->for($other)->processed()->create();
    $tag = Tag::create(['name' => 'secret', 'slug' => 'secret']);
    $otherBookmark->tags()->attach($tag->id);

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/tags')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

test('returns empty when user has no tags', function () {
    Sanctum::actingAs(User::factory()->create());

    $this->getJson('/api/v1/tags')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

test('can filter bookmarks by tag', function () {
    $user = User::factory()->create();

    $bookmarkA = Bookmark::factory()->for($user)->processed()->create(['title' => 'Laravel Article']);
    $bookmarkB = Bookmark::factory()->for($user)->processed()->create(['title' => 'Python Article']);

    $laravelTag = Tag::create(['name' => 'laravel', 'slug' => 'laravel']);
    $pythonTag = Tag::create(['name' => 'python', 'slug' => 'python']);

    $bookmarkA->tags()->attach($laravelTag->id);
    $bookmarkB->tags()->attach($pythonTag->id);

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/bookmarks?tag=laravel')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'Laravel Article');
});

test('tag filter returns empty when no bookmarks match', function () {
    $user = User::factory()->create();
    Bookmark::factory()->for($user)->processed()->create();

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/bookmarks?tag=nonexistent')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

test('requires authentication to list tags', function () {
    $this->getJson('/api/v1/tags')->assertUnauthorized();
});
