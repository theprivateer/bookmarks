<?php

namespace App\Console\Commands;

use App\Jobs\ProcessBookmark;
use App\Models\Bookmark;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Database\Eloquent\Builder;

#[Signature('bookmarks:refetch
    {--limit= : Maximum number of bookmarks to queue}
    {--only-missing : Only queue bookmarks that have no fetched content yet}
    {--force : Skip the production confirmation prompt}')]
#[Description('Queue bookmark content fetching using the ProcessBookmark job')]
class RefetchBookmarks extends Command
{
    use ConfirmableTrait;

    public function handle(): int
    {
        // Every queued job is an outbound page fetch, a third-party markdown call
        // and a paid AI analysis, so an unbounded run over a large library is
        // expensive and cannot be called back once queued.
        if (! $this->confirmToProceed()) {
            return self::FAILURE;
        }

        $limit = $this->limit();

        if ($limit !== null && $limit < 1) {
            $this->error('The --limit option must be a positive integer.');

            return self::FAILURE;
        }

        $queued = 0;

        // take() is applied to the LazyCollection rather than the query builder,
        // because lazyById() sets its own limit per chunk and would discard one
        // applied upstream.
        $bookmarks = $this->query()
            ->select('id')
            ->orderBy('id')
            ->lazyById();

        if ($limit !== null) {
            $bookmarks = $bookmarks->take($limit);
        }

        $bookmarks->each(function (Bookmark $bookmark) use (&$queued): void {
            ProcessBookmark::dispatch($bookmark->id);
            $queued++;
        });

        $this->info("Queued {$queued} bookmark(s) for refetch.");

        return self::SUCCESS;
    }

    /**
     * @return Builder<Bookmark>
     */
    private function query(): Builder
    {
        return Bookmark::query()->when(
            $this->option('only-missing'),
            fn (Builder $query) => $query->whereNull('extracted_text')->whereNull('markdown_text'),
        );
    }

    private function limit(): ?int
    {
        $limit = $this->option('limit');

        return $limit === null ? null : (int) $limit;
    }
}
