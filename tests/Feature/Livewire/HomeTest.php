<?php

use App\Jobs\AnalyseBookmark;
use App\Livewire\Header\AddBookmark;
use App\Livewire\Home;
use App\Models\Bookmark;
use App\Models\Collection;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Laravel\Ai\Embeddings;
use Livewire\Livewire;

test('home page renders the add bookmark input', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/')
        ->assertOk()
        ->assertSeeLivewire(Home::class)
        ->assertSeeLivewire(AddBookmark::class);
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

test('search returns bookmarks without embeddings via keyword match', function () {
    Embeddings::fake([[array_fill(0, 1536, 0.1)]]);

    $user = User::factory()->create();

    Bookmark::factory()->for($user)->create([
        'status' => 'processed',
        'title' => 'Known Bookmark',
        'description' => 'Useful saved page',
        'extracted_text' => 'Exact match from keyword search',
        'markdown_text' => 'Exact match from keyword search',
        'embedding' => null,
    ]);

    $component = Livewire::actingAs($user)
        ->test(Home::class)
        ->set('search', 'Known Bookmark')
        ->call('searchBookmarks');

    $bookmarks = $component->viewData('bookmarks');

    expect(collect($bookmarks->items())->pluck('title')->all())
        ->toBe(['Known Bookmark']);
});

test('search can use extracted text when configured', function () {
    config()->set('bookmarks.analysis_source_column', 'extracted_text');
    Embeddings::fake([[array_fill(0, 1536, 0.1)]]);

    $user = User::factory()->create();

    Bookmark::factory()->for($user)->create([
        'status' => 'processed',
        'title' => 'Configured Search Result',
        'description' => 'Uses extracted text when configured.',
        'extracted_text' => 'keyword only in extracted text',
        'markdown_text' => 'different markdown content',
        'embedding' => null,
    ]);

    $component = Livewire::actingAs($user)
        ->test(Home::class)
        ->set('search', 'keyword only in extracted text')
        ->call('searchBookmarks');

    $bookmarks = $component->viewData('bookmarks');

    expect(collect($bookmarks->items())->pluck('title')->all())
        ->toBe(['Configured Search Result']);
});

test('search de-duplicates bookmarks that match keyword and semantic search', function () {
    Embeddings::fake([[array_fill(0, 1536, 0.1)]]);

    $user = User::factory()->create();

    Bookmark::factory()->for($user)->processed()->create([
        'title' => 'Laravel Framework',
        'description' => 'Laravel notes',
        'extracted_text' => 'Laravel framework guide',
        'markdown_text' => 'Laravel framework guide',
    ]);

    $component = Livewire::actingAs($user)
        ->test(Home::class)
        ->set('search', 'Laravel')
        ->call('searchBookmarks');

    $bookmarks = $component->viewData('bookmarks');

    expect(collect($bookmarks->items())->pluck('title')->all())
        ->toBe(['Laravel Framework']);
});

test('search ranks keyword matches ahead of semantic only matches', function () {
    Embeddings::fake([[array_fill(0, 1536, 0.1)]]);

    $user = User::factory()->create();

    Bookmark::factory()->for($user)->create([
        'status' => 'processed',
        'title' => 'Knownterm Result',
        'description' => 'Exact keyword result',
        'extracted_text' => 'Knownterm appears here',
        'markdown_text' => 'Knownterm appears here',
        'embedding' => null,
    ]);

    Bookmark::factory()->for($user)->processed()->create([
        'title' => 'Semantic Result',
        'description' => 'Different text',
        'extracted_text' => 'Nothing related',
        'markdown_text' => 'Nothing related',
        'ai_summary' => 'Semantic only item',
    ]);

    $component = Livewire::actingAs($user)
        ->test(Home::class)
        ->set('search', 'Knownterm')
        ->call('searchBookmarks');

    $bookmarks = $component->viewData('bookmarks');

    expect(collect($bookmarks->items())->pluck('title')->all())
        ->toBe(['Knownterm Result', 'Semantic Result']);
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
    $bookmark = Bookmark::factory()->for($user)->create([
        'status' => 'processed',
        'title' => 'Tagged Search Result',
        'description' => 'Keyword filter match',
        'extracted_text' => 'Tagged Search Result',
        'markdown_text' => 'Tagged Search Result',
        'embedding' => null,
    ]);
    Bookmark::factory()->for($user)->create([
        'status' => 'processed',
        'title' => 'Untagged Search Result',
        'description' => 'Keyword filter match',
        'extracted_text' => 'Tagged Search Result',
        'markdown_text' => 'Tagged Search Result',
        'embedding' => null,
    ]);
    $tag = Tag::create(['name' => 'laravel', 'slug' => 'laravel']);
    $bookmark->tags()->attach($tag->id);

    $component = Livewire::actingAs($user)
        ->test(Home::class)
        ->call('filterByTag', 'laravel')
        ->set('search', 'Tagged Search Result')
        ->call('searchBookmarks');

    $bookmarks = $component->viewData('bookmarks');

    expect(collect($bookmarks->items())->pluck('title')->all())
        ->toBe(['Tagged Search Result']);
});

test('search and collection filter stack together', function () {
    Embeddings::fake([[array_fill(0, 1536, 0.1)]]);

    $user = User::factory()->create();
    $collection = Collection::factory()->for($user)->create(['slug' => 'research']);

    $inCollection = Bookmark::factory()->for($user)->processed()->create([
        'title' => 'In Collection',
        'description' => 'Different text',
        'extracted_text' => 'Nothing related',
        'markdown_text' => 'Nothing related',
        'ai_summary' => 'Semantic only item',
    ]);
    $inCollection->collections()->attach($collection->id);

    Bookmark::factory()->for($user)->create([
        'status' => 'processed',
        'title' => 'Research Keyword Match',
        'description' => 'Keyword only result',
        'extracted_text' => 'Research Keyword Match',
        'markdown_text' => 'Research Keyword Match',
        'embedding' => null,
    ]);

    $component = Livewire::actingAs($user)
        ->test(Home::class)
        ->set('collectionFilter', 'research')
        ->set('search', 'Research Keyword Match')
        ->call('searchBookmarks');

    $bookmarks = $component->viewData('bookmarks');

    expect(collect($bookmarks->items())->pluck('title')->all())
        ->toBe(['In Collection']);
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

test('analysis failed bookmarks show retry button', function () {
    $user = User::factory()->create();
    Bookmark::factory()->for($user)->analysisFailed()->create(['title' => 'Broken Analysis']);

    Livewire::actingAs($user)
        ->test(Home::class)
        ->assertSee('Analysis failed')
        ->assertSee('Broken Analysis');
});

test('can retry analysis for analysis_failed bookmark', function () {
    Queue::fake();

    $user = User::factory()->create();
    $bookmark = Bookmark::factory()->for($user)->analysisFailed()->create();

    Livewire::actingAs($user)
        ->test(Home::class)
        ->call('retryAnalysis', $bookmark->id)
        ->assertHasNoErrors();

    expect($bookmark->fresh()->status)->toBe('analysis_failed');
    Queue::assertPushed(AnalyseBookmark::class, fn ($job) => $job->bookmarkId === $bookmark->id);
});

test('can retry analysis for processed bookmark with null summary', function () {
    Queue::fake();

    $user = User::factory()->create();
    $bookmark = Bookmark::factory()->for($user)->processed()->create([
        'ai_summary' => null,
        'embedding' => null,
    ]);

    Livewire::actingAs($user)
        ->test(Home::class)
        ->call('retryAnalysis', $bookmark->id)
        ->assertHasNoErrors();

    Queue::assertPushed(AnalyseBookmark::class);
});

test('cannot retry analysis for a fully processed bookmark', function () {
    $user = User::factory()->create();
    $bookmark = Bookmark::factory()->for($user)->processed()->create();

    expect(fn () => Livewire::actingAs($user)
        ->test(Home::class)
        ->call('retryAnalysis', $bookmark->id)
    )->toThrow(ModelNotFoundException::class);
});
