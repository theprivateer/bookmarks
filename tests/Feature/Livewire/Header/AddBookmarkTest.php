<?php

use App\Jobs\ProcessBookmark;
use App\Livewire\Header\AddBookmark;
use App\Models\Bookmark;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

test('add bookmark header component renders for authenticated users', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(AddBookmark::class)
        ->assertSee('Add bookmark');
});

test('can add a bookmark from the shared header', function () {
    Queue::fake();

    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(AddBookmark::class)
        ->set('newUrl', 'https://example.com')
        ->call('addBookmark')
        ->assertHasNoErrors()
        ->assertSet('newUrl', '');

    expect(Bookmark::where('url', 'https://example.com')->exists())->toBeTrue();

    Queue::assertPushed(ProcessBookmark::class);
});

test('url is required when adding a bookmark from the shared header', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(AddBookmark::class)
        ->set('newUrl', '')
        ->call('addBookmark')
        ->assertHasErrors(['newUrl' => 'required']);
});

test('url must be valid when adding a bookmark from the shared header', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(AddBookmark::class)
        ->set('newUrl', 'not-a-url')
        ->call('addBookmark')
        ->assertHasErrors(['newUrl' => 'url']);
});
