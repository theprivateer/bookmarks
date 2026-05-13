<?php

namespace App\Models;

use Database\Factories\BookmarkFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection as SupportCollection;

#[Fillable(['url', 'domain', 'title', 'description', 'og_image_url', 'favicon_url', 'extracted_text', 'markdown_text', 'ai_summary', 'notes', 'embedding', 'status'])]
class Bookmark extends Model
{
    /** @use HasFactory<BookmarkFactory> */
    use HasFactory, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'embedding' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsToMany<Tag, $this>
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }

    /**
     * @return BelongsToMany<Collection, $this>
     */
    public function collections(): BelongsToMany
    {
        return $this->belongsToMany(Collection::class);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeProcessed(Builder $query): Builder
    {
        return $query->where('status', 'processed');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', 'failed');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeAnalysisFailed(Builder $query): Builder
    {
        return $query->where('status', 'analysis_failed');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeNeedsAnalysis(Builder $query): Builder
    {
        // Two distinct cases: a previous analysis run explicitly failed, or the bookmark was
        // processed but the AI step never completed (missing summary or embedding).
        return $query->where('status', 'analysis_failed')
            ->orWhere(fn (Builder $q) => $q->where('status', 'processed')->where(
                fn (Builder $q) => $q->whereNull('ai_summary')->orWhereNull('embedding')
            ));
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeKeywordSearch(Builder $query, string $search): Builder
    {
        $term = '%'.$this->escapeLike($search).'%';

        return $query->where(function (Builder $query) use ($term): void {
            $analysisSourceColumn = self::analysisSourceColumn();

            $query->where('title', 'ilike', $term)
                ->orWhere('description', 'ilike', $term)
                ->orWhere($analysisSourceColumn, 'ilike', $term);
        });
    }

    public static function analysisSourceColumn(): string
    {
        $column = config('bookmarks.analysis_source_column', 'markdown_text');

        // Whitelist prevents an unexpected config value from being interpolated
        // directly into a query as a column name.
        return in_array($column, ['extracted_text', 'markdown_text'], true)
            ? $column
            : 'markdown_text';
    }

    /**
     * @param  Builder<self>|Relation<self, self, *>  $query
     */
    public static function paginateCombinedSearch(
        Builder|Relation $query,
        string $search,
        int $perPage = 15,
        string $pageName = 'page',
    ): LengthAwarePaginator {
        // Keyword and vector searches run as separate queries because there is no clean
        // way to union an ILIKE filter with a pgvector similarity scan in a single
        // statement. Keyword matches are prepended so exact hits rank above semantic ones.
        $keywordMatches = (clone $query)
            ->keywordSearch($search)
            ->latest()
            ->get();

        $semanticMatches = (clone $query)
            ->whereVectorSimilarTo('embedding', $search, minSimilarity: 0.3)
            ->get();

        /** @var SupportCollection<int, self> $mergedResults */
        $mergedResults = $keywordMatches
            ->concat($semanticMatches)
            ->unique('id')
            ->values();

        $currentPage = LengthAwarePaginator::resolveCurrentPage($pageName);

        return new LengthAwarePaginator(
            $mergedResults->forPage($currentPage, $perPage)->values(),
            $mergedResults->count(),
            $perPage,
            $currentPage,
            [
                'path' => Paginator::resolveCurrentPath(),
                'pageName' => $pageName,
                'query' => request()->except($pageName),
            ],
        );
    }

    private function escapeLike(string $value): string
    {
        return addcslashes($value, '\\%_');
    }
}
