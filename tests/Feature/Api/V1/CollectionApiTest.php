<?php

use App\Models\Bookmark;
use App\Models\Collection;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

test('can list collections', function () {
    $user = User::factory()->create();
    Collection::factory()->count(3)->for($user)->create();

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/collections')
        ->assertOk()
        ->assertJsonCount(3, 'data');
});

test('list only returns own collections', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();

    Collection::factory()->count(2)->for($user)->create();
    Collection::factory()->count(3)->for($other)->create();

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/collections')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

test('can create a collection', function () {
    Sanctum::actingAs(User::factory()->create());

    $this->postJson('/api/v1/collections', ['name' => 'Laravel Resources'])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Laravel Resources')
        ->assertJsonPath('data.slug', 'laravel-resources');
});

test('collection name is required', function () {
    Sanctum::actingAs(User::factory()->create());

    $this->postJson('/api/v1/collections', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('name');
});

test('can show a collection with its bookmarks', function () {
    $user = User::factory()->create();
    $collection = Collection::factory()->for($user)->create();
    $bookmark = Bookmark::factory()->for($user)->processed()->create();
    $bookmark->collections()->attach($collection->id);

    Sanctum::actingAs($user);

    $this->getJson("/api/v1/collections/{$collection->id}")
        ->assertOk()
        ->assertJsonPath('data.name', $collection->name)
        ->assertJsonCount(1, 'data.bookmarks');
});

test('cannot show another users collection', function () {
    $other = User::factory()->create();
    $collection = Collection::factory()->for($other)->create();

    Sanctum::actingAs(User::factory()->create());

    $this->getJson("/api/v1/collections/{$collection->id}")
        ->assertNotFound();
});

test('can rename a collection', function () {
    $user = User::factory()->create();
    $collection = Collection::factory()->for($user)->create(['name' => 'Old Name']);

    Sanctum::actingAs($user);

    $this->putJson("/api/v1/collections/{$collection->id}", ['name' => 'New Name'])
        ->assertOk()
        ->assertJsonPath('data.name', 'New Name')
        ->assertJsonPath('data.slug', 'new-name');
});

test('can delete a collection', function () {
    $user = User::factory()->create();
    $collection = Collection::factory()->for($user)->create();

    Sanctum::actingAs($user);

    $this->deleteJson("/api/v1/collections/{$collection->id}")
        ->assertNoContent();

    expect(Collection::find($collection->id))->toBeNull();
});

test('can filter bookmarks by collection', function () {
    $user = User::factory()->create();
    $collection = Collection::factory()->for($user)->create(['slug' => 'laravel']);

    $inCollection = Bookmark::factory()->for($user)->processed()->create(['title' => 'In Collection']);
    Bookmark::factory()->for($user)->processed()->create(['title' => 'Not In Collection']);
    $inCollection->collections()->attach($collection->id);

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/bookmarks?collection=laravel')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'In Collection');
});
