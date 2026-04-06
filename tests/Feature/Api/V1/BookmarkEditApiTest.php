<?php

use App\Models\Bookmark;
use App\Models\Collection;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

test('can update bookmark title and description', function () {
    $user = User::factory()->create();
    $bookmark = Bookmark::factory()->for($user)->processed()->create([
        'title' => 'Old Title',
        'description' => 'Old description',
    ]);

    Sanctum::actingAs($user);

    $this->putJson("/api/v1/bookmarks/{$bookmark->id}", [
        'title' => 'New Title',
        'description' => 'New description',
    ])
        ->assertOk()
        ->assertJsonPath('data.title', 'New Title')
        ->assertJsonPath('data.description', 'New description');
});

test('can update bookmark notes', function () {
    $user = User::factory()->create();
    $bookmark = Bookmark::factory()->for($user)->processed()->create();

    Sanctum::actingAs($user);

    $this->putJson("/api/v1/bookmarks/{$bookmark->id}", [
        'notes' => 'My notes',
    ])
        ->assertOk()
        ->assertJsonPath('data.notes', 'My notes');
});

test('can sync tags via api', function () {
    $user = User::factory()->create();
    $bookmark = Bookmark::factory()->for($user)->processed()->create();

    Sanctum::actingAs($user);

    $this->putJson("/api/v1/bookmarks/{$bookmark->id}", [
        'tags' => ['laravel', 'php'],
    ])
        ->assertOk();

    $bookmark->refresh();
    expect($bookmark->tags)->toHaveCount(2)
        ->and($bookmark->tags->pluck('name')->sort()->values()->all())->toBe(['laravel', 'php']);
});

test('can sync collection ids via api', function () {
    $user = User::factory()->create();
    $bookmark = Bookmark::factory()->for($user)->processed()->create();
    $collection = Collection::factory()->for($user)->create();

    Sanctum::actingAs($user);

    $this->putJson("/api/v1/bookmarks/{$bookmark->id}", [
        'collection_ids' => [$collection->id],
    ])
        ->assertOk();

    expect($bookmark->fresh()->collections)->toHaveCount(1);
});

test('cannot assign bookmark to another users collection', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $bookmark = Bookmark::factory()->for($user)->processed()->create();
    $collection = Collection::factory()->for($other)->create();

    Sanctum::actingAs($user);

    $this->putJson("/api/v1/bookmarks/{$bookmark->id}", [
        'collection_ids' => [$collection->id],
    ])
        ->assertOk();

    expect($bookmark->fresh()->collections)->toHaveCount(0);
});

test('title validation enforces max length', function () {
    $user = User::factory()->create();
    $bookmark = Bookmark::factory()->for($user)->processed()->create();

    Sanctum::actingAs($user);

    $this->putJson("/api/v1/bookmarks/{$bookmark->id}", [
        'title' => str_repeat('a', 256),
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('title');
});

test('archive still works alongside field updates', function () {
    $user = User::factory()->create();
    $bookmark = Bookmark::factory()->for($user)->processed()->create();

    Sanctum::actingAs($user);

    $this->putJson("/api/v1/bookmarks/{$bookmark->id}", [
        'archived' => true,
    ])
        ->assertOk();

    $this->assertSoftDeleted('bookmarks', ['id' => $bookmark->id]);
});
