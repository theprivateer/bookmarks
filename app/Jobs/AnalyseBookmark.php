<?php

namespace App\Jobs;

use App\Ai\Agents\BookmarkAnalyser;
use App\Ai\Agents\BookmarkAnalysisSynthesizer;
use App\Ai\BookmarkContentPreparer;
use App\Ai\EmbeddingAggregator;
use App\Ai\ParagraphChunker;
use App\Models\Bookmark;
use App\Models\Tag;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Ai\Embeddings;
use Throwable;

class AnalyseBookmark implements ShouldQueue
{
    use Queueable;

    private const EMBEDDING_DIMENSIONS = 1536;

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

        $preparedContent = app(BookmarkContentPreparer::class)->prepare(
            $bookmark->title,
            $bookmark->description,
            $bookmark->extracted_text,
        );

        if ($preparedContent === null) {
            Log::warning('AnalyseBookmark skipped due to empty prepared content', [
                'bookmark_id' => $bookmark->id,
            ]);

            return;
        }

        $analysisChunks = $this->analysisChunks($preparedContent);

        if ($analysisChunks === []) {
            Log::warning('AnalyseBookmark produced no analysis chunks', [
                'bookmark_id' => $bookmark->id,
            ]);

            return;
        }

        Log::info('AnalyseBookmark chunked content', [
            'bookmark_id' => $bookmark->id,
            'analysis_chunks' => count($analysisChunks),
            'embedding_chunk_budget' => $this->embeddingChunkBudget(),
            'analysis_chunk_budget' => $this->analysisChunkBudget(),
            'approx_input_tokens' => $this->estimateTokens($preparedContent['content']),
        ]);

        $analysisResponse = $this->analysePreparedContent($preparedContent, $analysisChunks);
        $tagIds = $this->syncTags($analysisResponse['tags']);
        $bookmark->tags()->sync($tagIds);

        $bookmarkEmbedding = $this->generateAggregatedEmbedding($preparedContent);

        $bookmark->update([
            'ai_summary' => $analysisResponse['summary'],
            'embedding' => $bookmarkEmbedding,
            'status' => 'processed',
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('AnalyseBookmark job failed', [
            'bookmark_id' => $this->bookmarkId,
            'error' => $exception?->getMessage(),
        ]);

        Bookmark::where('id', $this->bookmarkId)->update(['status' => 'analysis_failed']);
    }

    /**
     * @param  array{title: string|null, description: string|null, content: string}  $preparedContent
     * @param  list<string>  $analysisChunks
     * @return array{summary: string, tags: list<string>}
     */
    private function analysePreparedContent(array $preparedContent, array $analysisChunks): array
    {
        if (count($analysisChunks) === 1) {
            /** @var array{summary: string, tags: list<string>} $response */
            $response = (new BookmarkAnalyser)->prompt(
                $this->analysisPrompt($preparedContent, $analysisChunks[0], 1, 1),
            );

            return [
                'summary' => $response['summary'],
                'tags' => $this->normalizeTags($response['tags']),
            ];
        }

        $chunkResponses = collect($analysisChunks)
            ->values()
            ->map(function (string $chunk, int $index) use ($analysisChunks, $preparedContent): array {
                $response = (new BookmarkAnalyser)->prompt(
                    $this->analysisPrompt($preparedContent, $chunk, $index + 1, count($analysisChunks)),
                );

                return [
                    'summary' => $response['summary'],
                    'tags' => $this->normalizeTags($response['tags']),
                ];
            });

        $candidateTags = $chunkResponses
            ->flatMap(fn (array $response): array => $this->normalizeTags($response['tags']))
            ->unique()
            ->values()
            ->take($this->maxCandidateTags())
            ->all();

        /** @var array{summary: string, tags: list<string>} $response */
        $response = (new BookmarkAnalysisSynthesizer)->prompt(
            $this->synthesisPrompt($preparedContent, $chunkResponses, $candidateTags),
        );

        return [
            'summary' => $response['summary'],
            'tags' => $this->normalizeTags($response['tags']),
        ];
    }

    /**
     * @param  array{title: string|null, description: string|null, content: string}  $preparedContent
     * @return list<float>
     */
    private function generateAggregatedEmbedding(array $preparedContent): array
    {
        $inputs = $this->embeddingInputs($preparedContent);
        $vectors = [];

        foreach ($inputs as $input) {
            foreach ($this->generateEmbeddingVectors($input, $this->embeddingChunkBudget()) as $vector) {
                $vectors[] = $vector;
            }
        }

        return app(EmbeddingAggregator::class)->aggregate($vectors);
    }

    /**
     * @param  array{title: string|null, description: string|null, content: string}  $preparedContent
     * @return list<string>
     */
    private function analysisChunks(array $preparedContent): array
    {
        return app(ParagraphChunker::class)->chunk(
            $preparedContent['content'],
            $this->analysisChunkBudget(),
            $this->chunkOverlap(),
            $this->maxChunks(),
        );
    }

    /**
     * @param  array{title: string|null, description: string|null, content: string}  $preparedContent
     * @return list<string>
     */
    private function embeddingInputs(array $preparedContent): array
    {
        $prefix = $this->contextPrefix($preparedContent);
        $prefixLength = mb_strlen($prefix);
        $bodyBudget = max(200, $this->embeddingChunkBudget() - $prefixLength - 2);
        $bodyChunks = app(ParagraphChunker::class)->chunk(
            $preparedContent['content'],
            $bodyBudget,
            max(0, min($this->chunkOverlap(), intdiv($bodyBudget, 2))),
            $this->maxChunks(),
        );

        if ($bodyChunks === []) {
            return [$prefix];
        }

        return array_map(
            fn (string $chunk): string => trim($prefix."\n\n".$chunk),
            $bodyChunks,
        );
    }

    /**
     * @param  array{title: string|null, description: string|null, content: string}  $preparedContent
     */
    private function analysisPrompt(array $preparedContent, string $chunk, int $index, int $total): string
    {
        return implode("\n\n", array_filter([
            $this->contextPrefix($preparedContent),
            "Content chunk {$index} of {$total}:",
            $chunk,
        ]));
    }

    /**
     * @param  array{title: string|null, description: string|null, content: string}  $preparedContent
     * @param  Collection<int, array{summary: string, tags: list<string>}>  $chunkResponses
     * @param  list<string>  $candidateTags
     */
    private function synthesisPrompt(array $preparedContent, Collection $chunkResponses, array $candidateTags): string
    {
        $summaries = $chunkResponses
            ->values()
            ->map(fn (array $response, int $index): string => ($index + 1).'. '.$response['summary'])
            ->implode("\n");

        $tags = collect($candidateTags)
            ->map(fn (string $tag): string => '- '.$tag)
            ->implode("\n");

        return implode("\n\n", array_filter([
            $this->contextPrefix($preparedContent),
            'Chunk summaries:',
            $summaries,
            $tags !== '' ? "Candidate tags:\n{$tags}" : null,
        ]));
    }

    /**
     * @param  array{title: string|null, description: string|null, content: string}  $preparedContent
     */
    private function contextPrefix(array $preparedContent): string
    {
        return implode("\n\n", array_filter([
            $preparedContent['title'] ? 'Title: '.$preparedContent['title'] : null,
            $preparedContent['description'] ? 'Description: '.$preparedContent['description'] : null,
        ]));
    }

    /**
     * @return list<list<float>>
     */
    private function generateEmbeddingVectors(string $input, int $budget, int $depth = 0): array
    {
        try {
            return [
                Embeddings::for([$input])
                    ->dimensions(self::EMBEDDING_DIMENSIONS)
                    ->generate()
                    ->embeddings[0],
            ];
        } catch (Throwable $exception) {
            if (! $this->shouldRetryWithSmallerChunks($exception, $input, $budget, $depth)) {
                throw $exception;
            }

            $smallerBudget = max(200, intdiv($budget, 2));
            $smallerChunks = app(ParagraphChunker::class)->chunk($input, $smallerBudget, 0, 2);
            $vectors = [];

            foreach ($smallerChunks as $chunk) {
                foreach ($this->generateEmbeddingVectors($chunk, $smallerBudget, $depth + 1) as $vector) {
                    $vectors[] = $vector;
                }
            }

            return $vectors;
        }
    }

    private function shouldRetryWithSmallerChunks(Throwable $exception, string $input, int $budget, int $depth): bool
    {
        if (! Str::contains($exception->getMessage(), [
            'maximum input length',
            '8192 tokens',
            'input[0]',
        ])) {
            return false;
        }

        if ($depth >= 3 || mb_strlen($input) <= 200 || $budget <= 200) {
            return false;
        }

        return true;
    }

    /**
     * @param  list<string>  $tags
     * @return list<string>
     */
    private function normalizeTags(array $tags): array
    {
        return collect($tags)
            ->map(fn (string $tag): string => mb_strtolower(trim($tag)))
            ->filter()
            ->unique()
            ->take(5)
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $tags
     * @return list<int>
     */
    private function syncTags(array $tags): array
    {
        return collect($tags)
            ->take(5)
            ->map(function (string $name): Tag {
                $slug = Str::slug($name);
                $label = mb_strtolower(trim($name));

                return Tag::firstOrCreate(
                    ['slug' => $slug],
                    ['name' => $label, 'slug' => $slug],
                );
            })
            ->pluck('id')
            ->all();
    }

    private function analysisChunkBudget(): int
    {
        return (int) config('ai.bookmark_analysis.analysis_chunk_budget', 6_000);
    }

    private function embeddingChunkBudget(): int
    {
        return (int) config('ai.bookmark_analysis.embedding_chunk_budget', 4_000);
    }

    private function chunkOverlap(): int
    {
        return (int) config('ai.bookmark_analysis.chunk_overlap', 500);
    }

    private function maxChunks(): int
    {
        return (int) config('ai.bookmark_analysis.max_chunks', 12);
    }

    private function maxCandidateTags(): int
    {
        return (int) config('ai.bookmark_analysis.max_candidate_tags', 20);
    }

    private function estimateTokens(string $content): int
    {
        return (int) ceil(mb_strlen($content) / 3);
    }
}
