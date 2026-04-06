<?php

namespace App\Jobs;

use App\Ai\Agents\BookmarkAnalyser;
use App\Models\Bookmark;
use App\Models\Tag;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Ai\Embeddings;
use Throwable;

class AnalyseBookmark implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 60;

    public function __construct(public int $bookmarkId)
    {
        $this->afterCommit();
    }

    public function backoff(): array
    {
        return [10, 30];
    }

    public function handle(): void
    {
        $bookmark = Bookmark::findOrFail($this->bookmarkId);

        if (blank($bookmark->extracted_text)) {
            return;
        }

        $prompt = implode("\n\n", array_filter([
            $bookmark->title ? "Title: {$bookmark->title}" : null,
            'Content: '.Str::limit($bookmark->extracted_text, 8000),
        ]));

        $response = (new BookmarkAnalyser)->prompt($prompt);

        $tagIds = collect($response['tags'])
            ->take(5)
            ->map(function (string $name): Tag {
                $slug = Str::slug(mb_strtolower(trim($name)));
                $label = mb_strtolower(trim($name));

                return Tag::firstOrCreate(
                    ['slug' => $slug],
                    ['name' => $label, 'slug' => $slug],
                );
            })
            ->pluck('id')
            ->all();

        $bookmark->tags()->sync($tagIds);

        $embeddingInput = trim(($bookmark->title ?? '').' '.$bookmark->extracted_text);
        $embeddingResponse = Embeddings::for([$embeddingInput])
            ->dimensions(1536)
            ->generate();

        $bookmark->update([
            'ai_summary' => $response['summary'],
            'embedding' => $embeddingResponse->embeddings[0],
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('AnalyseBookmark job failed', [
            'bookmark_id' => $this->bookmarkId,
            'error' => $exception?->getMessage(),
        ]);
    }
}
