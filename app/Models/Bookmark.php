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
     * Columns permitted as the AI analysis source.
     */
    private const ANALYSIS_SOURCE_COLUMNS = ['extracted_text', 'markdown_text'];

    /**
     * Projection for search results, which are materialised in PHP rather than
     * paginated in SQL. Omits the two longText source columns and the 1536-float
     * embedding, none of which any resource or view reads — selecting them meant a
     * broad search pulled the entire corpus into memory. Add to this list only if
     * a listing genuinely needs the column.
     */
    private const LIST_COLUMNS = [
        'id',
        'user_id',
        'url',
        'domain',
        'title',
        'description',
        'og_image_url',
        'favicon_url',
        'ai_summary',
        'notes',
        'status',
        'deleted_at',
        'created_at',
        'updated_at',
    ];

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
            // Deliberately not restricted to the configured analysis source. Bookmarks
            // saved before markdown extraction existed only have extracted_text, and
            // one whose markdown fetch failed is in the same position, so keying search
            // off the config would make them silently unfindable by their body text.
            $query->where('title', 'ilike', $term)
                ->orWhere('description', 'ilike', $term)
                ->orWhere('extracted_text', 'ilike', $term)
                ->orWhere('markdown_text', 'ilike', $term);
        });
    }

    /**
     * Bookmarks with usable content in either source column.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeHasAnalysisSource(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->where(fn (Builder $q) => $q->whereNotNull('markdown_text')->where('markdown_text', '!=', ''))
            ->orWhere(fn (Builder $q) => $q->whereNotNull('extracted_text')->where('extracted_text', '!=', ''))
        );
    }

    /**
     * The configured preference for which column feeds AI analysis.
     */
    public static function analysisSourceColumn(): string
    {
        $column = config('bookmarks.analysis_source_column', 'markdown_text');

        // Whitelist prevents an unexpected config value from being interpolated
        // directly into a query as a column name.
        return in_array($column, self::ANALYSIS_SOURCE_COLUMNS, true)
            ? $column
            : 'markdown_text';
    }

    /**
     * The column actually used for this bookmark, falling back to the other one
     * when the preferred column is empty. Without the fallback, any bookmark whose
     * markdown fetch failed would never be analysed even though extracted_text
     * holds perfectly usable content.
     */
    public function resolvedAnalysisSourceColumn(): string
    {
        $preferred = self::analysisSourceColumn();

        if (filled($this->getAttribute($preferred))) {
            return $preferred;
        }

        $fallback = $preferred === 'markdown_text' ? 'extracted_text' : 'markdown_text';

        return filled($this->getAttribute($fallback)) ? $fallback : $preferred;
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
            ->get(self::LIST_COLUMNS);

        $semanticMatches = (clone $query)
            ->whereVectorSimilarTo('embedding', $search, minSimilarity: 0.3)
            ->get(self::LIST_COLUMNS);

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
