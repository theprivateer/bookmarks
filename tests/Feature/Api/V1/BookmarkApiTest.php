<?php

use App\Models\Bookmark;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

test('unauthenticated request returns 401', function () {
    $this->getJson('/api/v1/bookmarks')
        ->assertUnauthorized();
});

test('can create a bookmark with valid url', function () {
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
    Sanctum::actingAs(User::factory()->create());

    $this->postJson('/api/v1/bookmarks', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('url');
});

test('cannot create a bookmark with invalid url', function () {
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
