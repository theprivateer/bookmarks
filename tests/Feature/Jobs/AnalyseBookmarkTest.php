<?php

use App\Ai\Agents\BookmarkAnalyser;
use App\Ai\Agents\BookmarkAnalysisSynthesizer;
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
    BookmarkAnalysisSynthesizer::fake()->preventStrayPrompts();
    Embeddings::fake();

    $user = User::factory()->create();
    $bookmark = Bookmark::factory()->for($user)->processed()->create();

    (new AnalyseBookmark($bookmark->id))->handle();

    expect($bookmark->fresh()->ai_summary)->toBe('A great article about PHP.')
        ->and($bookmark->fresh()->status)->toBe('processed');
});

test('job uses markdown text by default', function () {
    BookmarkAnalyser::fake([
        ['summary' => 'Summary from markdown.', 'tags' => ['markdown']],
    ]);
    BookmarkAnalysisSynthesizer::fake()->preventStrayPrompts();
    Embeddings::fake();

    $user = User::factory()->create();
    $bookmark = Bookmark::factory()->for($user)->processed()->create([
        'title' => 'Markdown first',
        'extracted_text' => 'Legacy extracted source that should not be used.',
        'markdown_text' => '# Markdown first'."\n\n".'Use this content for AI analysis.',
    ]);

    (new AnalyseBookmark($bookmark->id))->handle();

    BookmarkAnalyser::assertPrompted(fn ($prompt) => $prompt->contains('Use this content for AI analysis.')
        && ! $prompt->contains('Legacy extracted source that should not be used.'));
    Embeddings::assertGenerated(fn ($prompt) => $prompt->contains('Use this content for AI analysis.'));
});

test('job can use extracted text when configured', function () {
    config()->set('bookmarks.analysis_source_column', 'extracted_text');

    BookmarkAnalyser::fake([
        ['summary' => 'Summary from extracted text.', 'tags' => ['legacy']],
    ]);
    BookmarkAnalysisSynthesizer::fake()->preventStrayPrompts();
    Embeddings::fake();

    $user = User::factory()->create();
    $bookmark = Bookmark::factory()->for($user)->processed()->create([
        'title' => 'Configured extracted source',
        'extracted_text' => 'Use the extracted text source.',
        'markdown_text' => 'Do not use this markdown source.',
    ]);

    (new AnalyseBookmark($bookmark->id))->handle();

    BookmarkAnalyser::assertPrompted(fn ($prompt) => $prompt->contains('Use the extracted text source.')
        && ! $prompt->contains('Do not use this markdown source.'));
    Embeddings::assertGenerated(fn ($prompt) => $prompt->contains('Use the extracted text source.'));
});

test('job creates and attaches tags to bookmark', function () {
    BookmarkAnalyser::fake([
        ['summary' => 'An article.', 'tags' => ['laravel', 'php', 'open-source']],
    ]);
    BookmarkAnalysisSynthesizer::fake()->preventStrayPrompts();
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
    BookmarkAnalysisSynthesizer::fake()->preventStrayPrompts();
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
    BookmarkAnalysisSynthesizer::fake()->preventStrayPrompts();
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
    BookmarkAnalysisSynthesizer::fake()->preventStrayPrompts();
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
    BookmarkAnalysisSynthesizer::fake()->preventStrayPrompts();
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
    BookmarkAnalysisSynthesizer::fake();
    Embeddings::fake();

    $user = User::factory()->create();
    $bookmark = Bookmark::factory()->for($user)->create([
        'status' => 'processed',
        'extracted_text' => null,
        'markdown_text' => null,
    ]);

    (new AnalyseBookmark($bookmark->id))->handle();

    BookmarkAnalyser::assertNeverPrompted();
    Embeddings::assertNothingGenerated();
});

test('job skips bookmarks with no configured source text', function () {
    config()->set('bookmarks.analysis_source_column', 'markdown_text');

    BookmarkAnalyser::fake()->preventStrayPrompts();
    BookmarkAnalysisSynthesizer::fake()->preventStrayPrompts();
    Embeddings::fake()->preventStrayEmbeddings();

    $user = User::factory()->create();
    $bookmark = Bookmark::factory()->for($user)->processed()->create([
        'extracted_text' => 'Only extracted text is present.',
        'markdown_text' => null,
        'ai_summary' => null,
        'embedding' => null,
    ]);

    (new AnalyseBookmark($bookmark->id))->handle();

    BookmarkAnalyser::assertNeverPrompted();
    Embeddings::assertNothingGenerated();
    expect($bookmark->fresh()->ai_summary)->toBeNull()
        ->and($bookmark->fresh()->embedding)->toBeNull();
});

test('job transitions analysis_failed bookmark to processed on success', function () {
    BookmarkAnalyser::fake([
        ['summary' => 'Retry succeeded.', 'tags' => ['php']],
    ]);
    BookmarkAnalysisSynthesizer::fake()->preventStrayPrompts();
    Embeddings::fake();

    $user = User::factory()->create();
    $bookmark = Bookmark::factory()->for($user)->analysisFailed()->create();

    (new AnalyseBookmark($bookmark->id))->handle();

    expect($bookmark->fresh()->status)->toBe('processed')
        ->and($bookmark->fresh()->ai_summary)->toBe('Retry succeeded.');
});

test('job sets status to analysis_failed on failure', function () {
    $user = User::factory()->create();
    $bookmark = Bookmark::factory()->for($user)->processed()->create();

    Log::shouldReceive('error')->once();

    $job = new AnalyseBookmark($bookmark->id);
    $job->failed(new Exception('AI service unavailable'));

    expect($bookmark->fresh()->status)->toBe('analysis_failed');
});

test('job synthesizes summary and tags from multiple analysis chunks', function () {
    config()->set('ai.bookmark_analysis.analysis_chunk_budget', 120);
    config()->set('ai.bookmark_analysis.embedding_chunk_budget', 120);
    config()->set('ai.bookmark_analysis.chunk_overlap', 0);

    BookmarkAnalyser::fake([
        ['summary' => 'Chunk one summary.', 'tags' => ['laravel', 'php']],
        ['summary' => 'Chunk two summary.', 'tags' => ['queues', 'jobs']],
    ]);
    BookmarkAnalysisSynthesizer::fake([
        ['summary' => 'Final synthesized summary.', 'tags' => ['laravel', 'php', 'queues', 'jobs']],
    ]);
    Embeddings::fake([
        [array_fill(0, 1536, 1.0)],
        [array_fill(0, 1536, 1.0)],
    ]);

    $user = User::factory()->create();
    $bookmark = Bookmark::factory()->for($user)->processed()->create([
        'title' => 'Long Laravel article',
        'description' => 'A deep dive into queues.',
        'extracted_text' => implode("\n\n", [
            str_repeat('Laravel queues help coordinate long running work. ', 3),
            str_repeat('Jobs, batching, retries, and workers all matter in production. ', 3),
        ]),
        'markdown_text' => implode("\n\n", [
            str_repeat('Laravel queues help coordinate long running work. ', 3),
            str_repeat('Jobs, batching, retries, and workers all matter in production. ', 3),
        ]),
    ]);

    (new AnalyseBookmark($bookmark->id))->handle();

    BookmarkAnalysisSynthesizer::assertPrompted(function ($prompt) {
        return $prompt->contains('Chunk one summary.')
            && $prompt->contains('Chunk two summary.')
            && $prompt->contains('Candidate tags:')
            && ! $prompt->contains('coordinate long running work');
    });

    expect($bookmark->fresh()->ai_summary)->toBe('Final synthesized summary.')
        ->and($bookmark->fresh()->tags->pluck('slug')->all())->toBe(['laravel', 'php', 'queues', 'jobs']);
});

test('job aggregates embeddings generated from multiple chunks', function () {
    config()->set('ai.bookmark_analysis.embedding_chunk_budget', 120);
    config()->set('ai.bookmark_analysis.chunk_overlap', 0);

    BookmarkAnalyser::fake([
        ['summary' => 'Chunked article.', 'tags' => ['php']],
    ]);
    BookmarkAnalysisSynthesizer::fake()->preventStrayPrompts();
    Embeddings::fake(function ($prompt) {
        $vector = array_fill(0, 1536, 0.0);

        if ($prompt->contains('First chunk marker')) {
            $vector[0] = 1.0;

            return [$vector];
        }

        if ($prompt->contains('Second chunk marker')) {
            $vector[1] = 1.0;

            return [$vector];
        }

        $vector[2] = 1.0;

        return [$vector];
    });

    $user = User::factory()->create();
    $bookmark = Bookmark::factory()->for($user)->processed()->create([
        'title' => 'Embedding chunks',
        'extracted_text' => implode("\n\n", [
            'First chunk marker. '.str_repeat('alpha ', 60),
            'Second chunk marker. '.str_repeat('beta ', 60),
        ]),
        'markdown_text' => implode("\n\n", [
            'First chunk marker. '.str_repeat('alpha ', 60),
            'Second chunk marker. '.str_repeat('beta ', 60),
        ]),
    ]);

    (new AnalyseBookmark($bookmark->id))->handle();

    Embeddings::assertGenerated(fn ($prompt) => $prompt->contains('First chunk marker'));
    Embeddings::assertGenerated(fn ($prompt) => $prompt->contains('Second chunk marker'));

    expect($bookmark->fresh()->embedding)->toHaveCount(1536)
        ->and(round($bookmark->fresh()->embedding[0], 6))->toBeGreaterThan(0.0)
        ->and(round($bookmark->fresh()->embedding[1], 6))->toBeGreaterThan(0.0)
        ->and(round($bookmark->fresh()->embedding[0], 6))->toBe(round($bookmark->fresh()->embedding[1], 6));
});

test('job splits embedding chunks again when provider rejects oversized input', function () {
    config()->set('ai.bookmark_analysis.analysis_chunk_budget', 5000);
    config()->set('ai.bookmark_analysis.embedding_chunk_budget', 220);
    config()->set('ai.bookmark_analysis.chunk_overlap', 0);

    BookmarkAnalyser::fake([
        ['summary' => 'Dense content.', 'tags' => ['php']],
    ]);
    BookmarkAnalysisSynthesizer::fake()->preventStrayPrompts();

    Embeddings::fake(function ($prompt) {
        static $oversizedCalls = 0;

        if ($prompt->contains('Sentence 1') && $prompt->contains('Sentence 8')) {
            $oversizedCalls++;

            throw new RuntimeException("Invalid 'input[0]': maximum input length is 8192 tokens.");
        }

        return [[1.0, ...array_fill(0, 1535, 0.0)]];
    });

    $user = User::factory()->create();
    $bookmark = Bookmark::factory()->for($user)->processed()->create([
        'title' => 'Dense page',
        'extracted_text' => implode(' ', array_map(
            fn (int $index): string => "Sentence {$index} with OVERSIZE TOKEN MARKER and dense documentation content.",
            range(1, 8),
        )),
        'markdown_text' => implode(' ', array_map(
            fn (int $index): string => "Sentence {$index} with OVERSIZE TOKEN MARKER and dense documentation content.",
            range(1, 8),
        )),
    ]);

    (new AnalyseBookmark($bookmark->id))->handle();

    expect($bookmark->fresh()->status)->toBe('processed')
        ->and($bookmark->fresh()->embedding)->toHaveCount(1536);
});

test('job skips ai calls when cleaned content is empty', function () {
    BookmarkAnalyser::fake()->preventStrayPrompts();
    BookmarkAnalysisSynthesizer::fake()->preventStrayPrompts();
    Embeddings::fake()->preventStrayEmbeddings();

    $user = User::factory()->create();
    $bookmark = Bookmark::factory()->for($user)->processed()->create([
        'title' => null,
        'description' => null,
        'extracted_text' => "https://example.com/docs https://example.com/api https://example.com/blog\n\nHome | Docs | Blog | API",
        'markdown_text' => "https://example.com/docs https://example.com/api https://example.com/blog\n\nHome | Docs | Blog | API",
        'ai_summary' => null,
        'embedding' => null,
    ]);

    (new AnalyseBookmark($bookmark->id))->handle();

    BookmarkAnalyser::assertNeverPrompted();
    BookmarkAnalysisSynthesizer::assertNeverPrompted();
    Embeddings::assertNothingGenerated();
    expect($bookmark->fresh()->ai_summary)->toBeNull()
        ->and($bookmark->fresh()->embedding)->toBeNull();
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
