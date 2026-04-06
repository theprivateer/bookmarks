<?php

use App\Ai\Agents\BookmarkAnalyser;
use App\Jobs\AnalyseBookmark;
use App\Jobs\ProcessBookmark;
use App\Models\Bookmark;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Embeddings;

test('job stores ai summary on bookmark', function () {
    BookmarkAnalyser::fake([
        ['summary' => 'A great article about PHP.', 'tags' => ['php']],
    ]);
    Embeddings::fake();

    $user = User::factory()->create();
    $bookmark = Bookmark::factory()->for($user)->processed()->create();

    (new AnalyseBookmark($bookmark->id))->handle();

    expect($bookmark->fresh()->ai_summary)->toBe('A great article about PHP.');
});

test('job creates and attaches tags to bookmark', function () {
    BookmarkAnalyser::fake([
        ['summary' => 'An article.', 'tags' => ['laravel', 'php', 'open-source']],
    ]);
    Embeddings::fake();

    $user = User::factory()->create();
    $bookmark = Bookmark::factory()->for($user)->processed()->create();

    (new AnalyseBookmark($bookmark->id))->handle();

    expect($bookmark->fresh()->tags)->toHaveCount(3)
        ->and(Tag::where('slug', 'laravel')->exists())->toBeTrue()
        ->and(Tag::where('slug', 'php')->exists())->toBeTrue()
        ->and(Tag::where('slug', 'open-source')->exists())->toBeTrue();
});

test('job caps tags at 5', function () {
    BookmarkAnalyser::fake([
        ['summary' => 'Article.', 'tags' => ['one', 'two', 'three', 'four', 'five', 'six']],
    ]);
    Embeddings::fake();

    $user = User::factory()->create();
    $bookmark = Bookmark::factory()->for($user)->processed()->create();

    (new AnalyseBookmark($bookmark->id))->handle();

    expect($bookmark->fresh()->tags)->toHaveCount(5);
});

test('job generates and stores embedding', function () {
    BookmarkAnalyser::fake([
        ['summary' => 'An article.', 'tags' => ['php']],
    ]);
    Embeddings::fake();

    $user = User::factory()->create();
    $bookmark = Bookmark::factory()->for($user)->processed()->create();

    (new AnalyseBookmark($bookmark->id))->handle();

    Embeddings::assertGenerated(fn ($prompt) => $prompt->contains($bookmark->title));

    expect($bookmark->fresh()->embedding)->toBeArray()->toHaveCount(1536);
});

test('job reuses existing tags by slug', function () {
    BookmarkAnalyser::fake([
        ['summary' => 'Article.', 'tags' => ['laravel']],
    ]);
    Embeddings::fake();

    $existingTag = Tag::create(['name' => 'laravel', 'slug' => 'laravel']);

    $user = User::factory()->create();
    $bookmark = Bookmark::factory()->for($user)->processed()->create();

    (new AnalyseBookmark($bookmark->id))->handle();

    expect(Tag::where('slug', 'laravel')->count())->toBe(1)
        ->and($bookmark->fresh()->tags->first()->id)->toBe($existingTag->id);
});

test('job is idempotent — syncing tags on retry does not duplicate', function () {
    BookmarkAnalyser::fake([
        ['summary' => 'Article.', 'tags' => ['laravel', 'php']],
        ['summary' => 'Article.', 'tags' => ['laravel', 'php']],
    ]);
    Embeddings::fake();

    $user = User::factory()->create();
    $bookmark = Bookmark::factory()->for($user)->processed()->create();

    (new AnalyseBookmark($bookmark->id))->handle();
    (new AnalyseBookmark($bookmark->id))->handle();

    expect($bookmark->fresh()->tags)->toHaveCount(2)
        ->and(Tag::count())->toBe(2);
});

test('job skips bookmarks with no extracted text', function () {
    BookmarkAnalyser::fake();
    Embeddings::fake();

    $user = User::factory()->create();
    $bookmark = Bookmark::factory()->for($user)->create([
        'status' => 'processed',
        'extracted_text' => null,
    ]);

    (new AnalyseBookmark($bookmark->id))->handle();

    BookmarkAnalyser::assertNeverPrompted();
    Embeddings::assertNothingGenerated();
});

test('job sets status to analysis_failed on failure', function () {
    $user = User::factory()->create();
    $bookmark = Bookmark::factory()->for($user)->processed()->create();

    Log::shouldReceive('error')->once();

    $job = new AnalyseBookmark($bookmark->id);
    $job->failed(new Exception('AI service unavailable'));

    expect($bookmark->fresh()->status)->toBe('analysis_failed');
});

test('analyse bookmark is dispatched after process bookmark completes', function () {
    Queue::fake();

    $user = User::factory()->create();
    $bookmark = Bookmark::factory()->for($user)->create([
        'url' => 'https://example.com',
        'domain' => 'example.com',
        'status' => 'pending',
    ]);

    Http::fake(['https://example.com' => Http::response('<html><head><title>Test</title></head><body><p>Content</p></body></html>')]);

    (new ProcessBookmark($bookmark->id))->handle();

    Queue::assertPushed(AnalyseBookmark::class, fn ($job) => $job->bookmarkId === $bookmark->id);
});
