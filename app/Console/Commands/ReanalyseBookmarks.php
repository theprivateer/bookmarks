<?php

namespace App\Console\Commands;

use App\Jobs\AnalyseBookmark;
use App\Models\Bookmark;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;

#[Signature('bookmarks:reanalyse {--force : Skip the production confirmation prompt}')]
#[Description('Queue bookmark AI analysis using the configured content source')]
class ReanalyseBookmarks extends Command
{
    use ConfirmableTrait;

    public function handle(): int
    {
        // Each queued job is a paid AI call, so a full run is not free.
        if (! $this->confirmToProceed()) {
            return self::FAILURE;
        }

        $sourceColumn = Bookmark::analysisSourceColumn();

        // Filtered in SQL rather than by loading each row and calling blank(): the
        // source columns are longText, and selecting them just to test for emptiness
        // pulled a chunk's worth of full documents into memory at a time.
        $total = Bookmark::query()->count();
        $queued = 0;

        Bookmark::query()
            ->hasAnalysisSource()
            ->select('id')
            ->orderBy('id')
            ->lazyById()
            ->each(function (Bookmark $bookmark) use (&$queued): void {
                AnalyseBookmark::dispatch($bookmark->id);
                $queued++;
            });

        $skipped = $total - $queued;

        $this->info("Queued {$queued} bookmark(s) for analysis using [{$sourceColumn}]. Skipped {$skipped} bookmark(s).");

        return self::SUCCESS;
    }
}
