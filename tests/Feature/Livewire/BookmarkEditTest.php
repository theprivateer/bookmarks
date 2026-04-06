<?php

use App\Livewire\Home;
use App\Models\Bookmark;
use App\Models\Collection;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;

test('can open edit modal and see bookmark data', function () {
    $user = User::factory()->create();
    $bookmark = Bookmark::factory()->for($user)->processed()->create([
        'title' => 'My Bookmark',
        'description' => 'A description',
        'notes' => 'Some notes',
    ]);
    $tag = Tag::create(['name' => 'laravel', 'slug' => 'laravel']);
    $bookmark->tags()->attach($tag->id);

    Livewire::actingAs($user)
        ->test(Home::class)
        ->call('editBookmark', $bookmark->id)
        ->assertSet('editTitle', 'My Bookmark')
        ->assertSet('editDescription', 'A description')
        ->assertSet('editNotes', 'Some notes')
        ->assertSet('editTags', 'laravel');
});

test('can update title and description', function () {
    $user = User::factory()->create();
    $bookmark = Bookmark::factory()->for($user)->processed()->create([
        'title' => 'Old Title',
        'description' => 'Old description',
    ]);

    Livewire::actingAs($user)
        ->test(Home::class)
        ->call('editBookmark', $bookmark->id)
        ->set('editTitle', 'New Title')
        ->set('editDescription', 'New description')
        ->call('updateBookmark')
        ->assertHasNoErrors();

    $bookmark->refresh();
    expect($bookmark->title)->toBe('New Title')
        ->and($bookmark->description)->toBe('New description');
});

test('can update notes', function () {
    $user = User::factory()->create();
    $bookmark = Bookmark::factory()->for($user)->processed()->create();

    Livewire::actingAs($user)
        ->test(Home::class)
        ->call('editBookmark', $bookmark->id)
        ->set('editNotes', 'My personal notes')
        ->call('updateBookmark')
        ->assertHasNoErrors();

    expect($bookmark->fresh()->notes)->toBe('My personal notes');
});

test('can update tags and creates new tags on the fly', function () {
    $user = User::factory()->create();
    $bookmark = Bookmark::factory()->for($user)->processed()->create();

    Livewire::actingAs($user)
        ->test(Home::class)
        ->call('editBookmark', $bookmark->id)
        ->set('editTags', 'laravel, php, testing')
        ->call('updateBookmark')
        ->assertHasNoErrors();

    $bookmark->refresh();
    expect($bookmark->tags)->toHaveCount(3)
        ->and($bookmark->tags->pluck('name')->sort()->values()->all())->toBe(['laravel', 'php', 'testing']);
});

test('can assign bookmark to collections', function () {
    $user = User::factory()->create();
    $bookmark = Bookmark::factory()->for($user)->processed()->create();
    $collection = Collection::factory()->for($user)->create(['name' => 'Reading List', 'slug' => 'reading-list']);

    Livewire::actingAs($user)
        ->test(Home::class)
        ->call('editBookmark', $bookmark->id)
        ->set('editCollectionIds', [$collection->id])
        ->call('updateBookmark')
        ->assertHasNoErrors();

    expect($bookmark->fresh()->collections)->toHaveCount(1)
        ->and($bookmark->fresh()->collections->first()->name)->toBe('Reading List');
});

test('edit title is required', function () {
    $user = User::factory()->create();
    $bookmark = Bookmark::factory()->for($user)->processed()->create();

    Livewire::actingAs($user)
        ->test(Home::class)
        ->call('editBookmark', $bookmark->id)
        ->set('editTitle', '')
        ->call('updateBookmark')
        ->assertHasErrors(['editTitle' => 'required']);
});

test('cannot edit another users bookmark', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $bookmark = Bookmark::factory()->for($other)->processed()->create();

    $this->actingAs($user)
        ->get('/')
        ->assertOk();

    expect(fn () => Livewire::actingAs($user)
        ->test(Home::class)
        ->call('editBookmark', $bookmark->id)
    )->toThrow(ModelNotFoundException::class);
});

test('notes are displayed on bookmark cards', function () {
    $user = User::factory()->create();
    Bookmark::factory()->for($user)->processed()->create(['notes' => 'Important reference']);

    Livewire::actingAs($user)
        ->test(Home::class)
        ->assertSee('Important reference');
});
