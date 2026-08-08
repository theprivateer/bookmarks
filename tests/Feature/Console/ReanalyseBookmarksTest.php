<?php

use App\Jobs\AnalyseBookmark;
use App\Models\Bookmark;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

test('bookmark schema includes nullable markdown text column', function () {
    expect(Schema::hasColumn('bookmarks', 'markdown_text'))->toBeTrue();
});

test('command queues analysis for bookmarks with content in either source column', function () {
    Queue::fake();

    $withMarkdown = Bookmark::factory()->processed()->create([
        'extracted_text' => null,
        'markdown_text' => 'Ready to reanalyse.',
    ]);

    // Falls back to extracted_text rather than being skipped, which is the whole
    // point: a failed markdown fetch must not make a bookmark unanalysable.
    $withExtractedOnly = Bookmark::factory()->processed()->create([
        'extracted_text' => 'Older bookmark with no markdown.',
        'markdown_text' => null,
    ]);

    $withNothing = Bookmark::factory()->create([
        'extracted_text' => null,
        'markdown_text' => null,
    ]);

    $this->artisan('bookmarks:reanalyse')
        ->expectsOutput('Queued 2 bookmark(s) for analysis using [markdown_text]. Skipped 1 bookmark(s).')
        ->assertSuccessful();

    Queue::assertPushed(AnalyseBookmark::class, 2);
    Queue::assertPushed(AnalyseBookmark::class, fn (AnalyseBookmark $job) => $job->bookmarkId === $withMarkdown->id);
    Queue::assertPushed(AnalyseBookmark::class, fn (AnalyseBookmark $job) => $job->bookmarkId === $withExtractedOnly->id);
    Queue::assertNotPushed(AnalyseBookmark::class, fn (AnalyseBookmark $job) => $job->bookmarkId === $withNothing->id);
});

test('command skips bookmarks whose source columns are empty strings', function () {
    Queue::fake();

    Bookmark::factory()->create([
        'extracted_text' => '',
        'markdown_text' => '',
    ]);

    $this->artisan('bookmarks:reanalyse')
        ->expectsOutput('Queued 0 bookmark(s) for analysis using [markdown_text]. Skipped 1 bookmark(s).')
        ->assertSuccessful();

    Queue::assertNothingPushed();
});

test('command reports the configured source column', function () {
    Queue::fake();
    config()->set('bookmarks.analysis_source_column', 'extracted_text');

    $bookmark = Bookmark::factory()->processed()->create([
        'extracted_text' => 'Use extracted text.',
        'markdown_text' => null,
    ]);

    $this->artisan('bookmarks:reanalyse')
        ->expectsOutput('Queued 1 bookmark(s) for analysis using [extracted_text]. Skipped 0 bookmark(s).')
        ->assertSuccessful();

    Queue::assertPushed(AnalyseBookmark::class, fn (AnalyseBookmark $job) => $job->bookmarkId === $bookmark->id);
});
