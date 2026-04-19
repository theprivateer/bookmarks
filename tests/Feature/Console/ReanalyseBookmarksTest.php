<?php

use App\Jobs\AnalyseBookmark;
use App\Models\Bookmark;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

test('bookmark schema includes nullable markdown text column', function () {
    expect(Schema::hasColumn('bookmarks', 'markdown_text'))->toBeTrue();
});

test('command queues analysis for bookmarks with configured source content', function () {
    Queue::fake();

    Bookmark::factory()->processed()->create([
        'markdown_text' => 'Ready to reanalyse.',
    ]);
    Bookmark::factory()->processed()->create([
        'markdown_text' => null,
    ]);

    $this->artisan('bookmarks:reanalyse')
        ->expectsOutput('Queued 1 bookmark(s) for analysis using [markdown_text]. Skipped 1 bookmark(s).')
        ->assertSuccessful();

    Queue::assertPushed(AnalyseBookmark::class, 1);
});

test('command respects extracted text when configured', function () {
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
