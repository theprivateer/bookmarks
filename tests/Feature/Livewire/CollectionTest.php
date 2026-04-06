<?php

use App\Livewire\Home;
use App\Models\Bookmark;
use App\Models\Collection;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;

test('can create a collection', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(Home::class)
        ->set('newCollectionName', 'Laravel Resources')
        ->call('createCollection')
        ->assertHasNoErrors();

    expect($user->collections)->toHaveCount(1)
        ->and($user->collections->first()->name)->toBe('Laravel Resources')
        ->and($user->collections->first()->slug)->toBe('laravel-resources');
});

test('collection name is required', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(Home::class)
        ->set('newCollectionName', '')
        ->call('createCollection')
        ->assertHasErrors(['newCollectionName' => 'required']);
});

test('can delete a collection', function () {
    $user = User::factory()->create();
    $collection = Collection::factory()->for($user)->create();

    Livewire::actingAs($user)
        ->test(Home::class)
        ->call('deleteCollection', $collection->id)
        ->assertHasNoErrors();

    expect(Collection::find($collection->id))->toBeNull();
});

test('deleting a collection does not delete its bookmarks', function () {
    $user = User::factory()->create();
    $collection = Collection::factory()->for($user)->create();
    $bookmark = Bookmark::factory()->for($user)->processed()->create();
    $bookmark->collections()->attach($collection->id);

    Livewire::actingAs($user)
        ->test(Home::class)
        ->call('deleteCollection', $collection->id);

    expect(Bookmark::find($bookmark->id))->not->toBeNull();
});

test('collection filter shows only bookmarks in that collection', function () {
    $user = User::factory()->create();
    $collection = Collection::factory()->for($user)->create(['name' => 'My Collection', 'slug' => 'my-collection']);

    $inCollection = Bookmark::factory()->for($user)->processed()->create(['title' => 'In Collection']);
    $outCollection = Bookmark::factory()->for($user)->processed()->create(['title' => 'Not In Collection']);
    $inCollection->collections()->attach($collection->id);

    Livewire::actingAs($user)
        ->test(Home::class)
        ->set('collectionFilter', 'my-collection')
        ->assertSee('In Collection')
        ->assertDontSee('Not In Collection');
});

test('clearing collection filter shows all bookmarks', function () {
    $user = User::factory()->create();
    $collection = Collection::factory()->for($user)->create(['slug' => 'my-collection']);

    $bookmark = Bookmark::factory()->for($user)->processed()->create(['title' => 'A Bookmark']);
    $bookmark->collections()->attach($collection->id);
    Bookmark::factory()->for($user)->processed()->create(['title' => 'Another Bookmark']);

    Livewire::actingAs($user)
        ->test(Home::class)
        ->set('collectionFilter', 'my-collection')
        ->assertDontSee('Another Bookmark')
        ->set('collectionFilter', '')
        ->assertSee('A Bookmark')
        ->assertSee('Another Bookmark');
});

test('can rename a collection', function () {
    $user = User::factory()->create();
    $collection = Collection::factory()->for($user)->create(['name' => 'Old Name', 'slug' => 'old-name']);

    Livewire::actingAs($user)
        ->test(Home::class)
        ->call('editCollection', $collection->id)
        ->assertSet('editCollectionName', 'Old Name')
        ->set('editCollectionName', 'New Name')
        ->call('updateCollection')
        ->assertHasNoErrors();

    $collection->refresh();
    expect($collection->name)->toBe('New Name')
        ->and($collection->slug)->toBe('new-name');
});

test('collection rename requires a name', function () {
    $user = User::factory()->create();
    $collection = Collection::factory()->for($user)->create();

    Livewire::actingAs($user)
        ->test(Home::class)
        ->call('editCollection', $collection->id)
        ->set('editCollectionName', '')
        ->call('updateCollection')
        ->assertHasErrors(['editCollectionName' => 'required']);
});

test('cannot delete another users collection', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $collection = Collection::factory()->for($other)->create();

    expect(fn () => Livewire::actingAs($user)
        ->test(Home::class)
        ->call('deleteCollection', $collection->id)
    )->toThrow(ModelNotFoundException::class);
});
