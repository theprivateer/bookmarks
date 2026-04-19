<?php

namespace App\Console\Commands;

use App\Jobs\AnalyseBookmark;
use App\Models\Bookmark;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('bookmarks:reanalyse')]
#[Description('Queue bookmark AI analysis using the configured content source')]
class ReanalyseBookmarks extends Command
{
    public function handle(): int
    {
        $sourceColumn = Bookmark::analysisSourceColumn();
        $queued = 0;
        $skipped = 0;

        Bookmark::query()
            ->select('id', $sourceColumn)
            ->orderBy('id')
            ->lazyById()
            ->each(function (Bookmark $bookmark) use ($sourceColumn, &$queued, &$skipped): void {
                if (blank($bookmark->getAttribute($sourceColumn))) {
                    $skipped++;

                    return;
                }

                AnalyseBookmark::dispatch($bookmark->id);
                $queued++;
            });

        $this->info("Queued {$queued} bookmark(s) for analysis using [{$sourceColumn}]. Skipped {$skipped} bookmark(s).");

        return self::SUCCESS;
    }
}
