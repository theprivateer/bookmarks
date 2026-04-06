<?php

use App\Jobs\ProcessBookmark;
use App\Livewire\Home;
use App\Models\Bookmark;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

test('home page renders the add bookmark input', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/')
        ->assertOk()
        ->assertSeeLivewire(Home::class);
});

test('can add a bookmark via the form', function () {
    Queue::fake();

    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(Home::class)
        ->set('newUrl', 'https://example.com')
        ->call('addBookmark')
        ->assertHasNoErrors();

    expect(Bookmark::where('url', 'https://example.com')->exists())->toBeTrue();

    Queue::assertPushed(ProcessBookmark::class);
});

test('url is required when adding a bookmark', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(Home::class)
        ->set('newUrl', '')
        ->call('addBookmark')
        ->assertHasErrors(['newUrl' => 'required']);
});

test('url must be a valid url', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(Home::class)
        ->set('newUrl', 'not-a-url')
        ->call('addBookmark')
        ->assertHasErrors(['newUrl' => 'url']);
});

test('bookmarks are displayed on the home page', function () {
    $user = User::factory()->create();
    $bookmark = Bookmark::factory()->for($user)->processed()->create(['title' => 'My Test Bookmark']);

    Livewire::actingAs($user)
        ->test(Home::class)
        ->assertSee('My Test Bookmark');
});

test('only own bookmarks are shown', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();

    Bookmark::factory()->for($user)->processed()->create(['title' => 'My Bookmark']);
    Bookmark::factory()->for($other)->processed()->create(['title' => 'Their Bookmark']);

    Livewire::actingAs($user)
        ->test(Home::class)
        ->assertSee('My Bookmark')
        ->assertDontSee('Their Bookmark');
});

test('pending bookmarks show processing indicator', function () {
    $user = User::factory()->create();
    Bookmark::factory()->for($user)->create(['status' => 'pending', 'title' => 'Pending Bookmark']);

    Livewire::actingAs($user)
        ->test(Home::class)
        ->assertSee('Processing...');
});

test('url is reset after adding a bookmark', function () {
    Queue::fake();

    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(Home::class)
        ->set('newUrl', 'https://example.com')
        ->call('addBookmark')
        ->assertSet('newUrl', '');
});
