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
