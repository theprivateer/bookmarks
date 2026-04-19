<?php

namespace App\Console\Commands;

use App\Jobs\ProcessBookmark;
use App\Models\Bookmark;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('bookmarks:refetch')]
#[Description('Queue bookmark content fetching using the ProcessBookmark job')]
class RefetchBookmarks extends Command
{
    public function handle(): int
    {
        $queued = 0;

        Bookmark::query()
            ->select('id')
            ->orderBy('id')
            ->lazyById()
            ->each(function (Bookmark $bookmark) use (&$queued): void {
                ProcessBookmark::dispatch($bookmark->id);
                $queued++;
            });

        $this->info("Queued {$queued} bookmark(s) for refetch.");

        return self::SUCCESS;
    }
}
