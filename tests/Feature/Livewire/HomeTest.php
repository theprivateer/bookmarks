<?php

use App\Jobs\ProcessBookmark;
use App\Livewire\Home;
use App\Models\Bookmark;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Embeddings;
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

test('tags are displayed on bookmark cards', function () {
    $user = User::factory()->create();
    $bookmark = Bookmark::factory()->for($user)->processed()->create(['title' => 'Tagged Bookmark']);
    $tag = Tag::create(['name' => 'laravel', 'slug' => 'laravel']);
    $bookmark->tags()->attach($tag->id);

    Livewire::actingAs($user)
        ->test(Home::class)
        ->assertSee('laravel');
});

test('tag filter shows tags in sidebar', function () {
    $user = User::factory()->create();
    $bookmark = Bookmark::factory()->for($user)->processed()->create();
    $tag = Tag::create(['name' => 'php', 'slug' => 'php']);
    $bookmark->tags()->attach($tag->id);

    Livewire::actingAs($user)
        ->test(Home::class)
        ->assertSee('php');
});

test('clicking a tag filters bookmarks', function () {
    $user = User::factory()->create();

    $bookmarkA = Bookmark::factory()->for($user)->processed()->create(['title' => 'Laravel Post']);
    $bookmarkB = Bookmark::factory()->for($user)->processed()->create(['title' => 'Python Post']);

    $laravelTag = Tag::create(['name' => 'laravel', 'slug' => 'laravel']);
    $pythonTag = Tag::create(['name' => 'python', 'slug' => 'python']);

    $bookmarkA->tags()->attach($laravelTag->id);
    $bookmarkB->tags()->attach($pythonTag->id);

    Livewire::actingAs($user)
        ->test(Home::class)
        ->call('filterByTag', 'laravel')
        ->assertSee('Laravel Post')
        ->assertDontSee('Python Post');
});

test('clearing tag filter shows all bookmarks', function () {
    $user = User::factory()->create();

    $bookmarkA = Bookmark::factory()->for($user)->processed()->create(['title' => 'Laravel Post']);
    $bookmarkB = Bookmark::factory()->for($user)->processed()->create(['title' => 'Python Post']);

    $tag = Tag::create(['name' => 'laravel', 'slug' => 'laravel']);
    $bookmarkA->tags()->attach($tag->id);

    Livewire::actingAs($user)
        ->test(Home::class)
        ->call('filterByTag', 'laravel')
        ->assertDontSee('Python Post')
        ->call('clearTagFilter')
        ->assertSee('Laravel Post')
        ->assertSee('Python Post');
});

test('search returns bookmarks with embeddings', function () {
    Embeddings::fake([[array_fill(0, 1536, 0.1)]]);

    $user = User::factory()->create();
    Bookmark::factory()->for($user)->processed()->create(['title' => 'Laravel Framework']);

    Livewire::actingAs($user)
        ->test(Home::class)
        ->set('search', 'php framework')
        ->call('searchBookmarks')
        ->assertSee('Laravel Framework');
});

test('search shows results for toolbar text', function () {
    Embeddings::fake([[array_fill(0, 1536, 0.1)]]);

    $user = User::factory()->create();
    Bookmark::factory()->for($user)->processed()->create(['title' => 'Some Article']);

    Livewire::actingAs($user)
        ->test(Home::class)
        ->set('search', 'my query')
        ->call('searchBookmarks')
        ->assertSee('my query');
});

test('clearing search restores normal listing', function () {
    Embeddings::fake([[array_fill(0, 1536, 0.1)]]);

    $user = User::factory()->create();
    Bookmark::factory()->for($user)->processed()->create(['title' => 'Test Bookmark']);

    Livewire::actingAs($user)
        ->test(Home::class)
        ->set('search', 'something')
        ->call('searchBookmarks')
        ->call('clearSearch')
        ->assertSet('search', '');
});

test('search and tag filter stack together', function () {
    Embeddings::fake([[array_fill(0, 1536, 0.1)]]);

    $user = User::factory()->create();
    $bookmark = Bookmark::factory()->for($user)->processed()->create(['title' => 'Laravel Article']);
    $tag = Tag::create(['name' => 'laravel', 'slug' => 'laravel']);
    $bookmark->tags()->attach($tag->id);

    Livewire::actingAs($user)
        ->test(Home::class)
        ->call('filterByTag', 'laravel')
        ->set('search', 'php framework')
        ->call('searchBookmarks')
        ->assertSet('tagFilter', 'laravel')
        ->assertSet('search', 'php framework');
});

test('empty search is ignored and shows normal listing', function () {
    $user = User::factory()->create();
    Bookmark::factory()->for($user)->processed()->create(['title' => 'My Bookmark']);

    Livewire::actingAs($user)
        ->test(Home::class)
        ->set('search', '')
        ->call('searchBookmarks')
        ->assertSet('isSearching', false)
        ->assertSee('My Bookmark');
});

test('search validates max length', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(Home::class)
        ->set('search', str_repeat('a', 501))
        ->call('searchBookmarks')
        ->assertHasErrors(['search']);
});
