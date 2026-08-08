<?php

use App\Jobs\ProcessBookmark;
use App\Models\Bookmark;
use Illuminate\Support\Facades\Queue;

test('command queues process bookmark for every bookmark', function () {
    Queue::fake();

    $firstBookmark = Bookmark::factory()->create();
    $secondBookmark = Bookmark::factory()->create();

    $this->artisan('bookmarks:refetch')
        ->expectsOutput('Queued 2 bookmark(s) for refetch.')
        ->assertSuccessful();

    Queue::assertPushed(ProcessBookmark::class, 2);
    Queue::assertPushed(ProcessBookmark::class, fn (ProcessBookmark $job) => $job->bookmarkId === $firstBookmark->id);
    Queue::assertPushed(ProcessBookmark::class, fn (ProcessBookmark $job) => $job->bookmarkId === $secondBookmark->id);
});

test('command reports when there are no bookmarks to refetch', function () {
    Queue::fake();

    $this->artisan('bookmarks:refetch')
        ->expectsOutput('Queued 0 bookmark(s) for refetch.')
        ->assertSuccessful();

    Queue::assertNothingPushed();
});

test('limit option caps how many bookmarks are queued', function () {
    Queue::fake();

    Bookmark::factory()->count(5)->create();

    $this->artisan('bookmarks:refetch', ['--limit' => 2])
        ->expectsOutput('Queued 2 bookmark(s) for refetch.')
        ->assertSuccessful();

    Queue::assertPushed(ProcessBookmark::class, 2);
});

test('limit option rejects a non positive value', function () {
    Queue::fake();

    Bookmark::factory()->create();

    $this->artisan('bookmarks:refetch', ['--limit' => 0])
        ->assertFailed();

    Queue::assertNothingPushed();
});

test('only missing option skips bookmarks that already have content', function () {
    Queue::fake();

    Bookmark::factory()->processed()->create();
    $empty = Bookmark::factory()->create([
        'extracted_text' => null,
        'markdown_text' => null,
    ]);

    $this->artisan('bookmarks:refetch', ['--only-missing' => true])
        ->expectsOutput('Queued 1 bookmark(s) for refetch.')
        ->assertSuccessful();

    Queue::assertPushed(ProcessBookmark::class, 1);
    Queue::assertPushed(ProcessBookmark::class, fn (ProcessBookmark $job) => $job->bookmarkId === $empty->id);
});
