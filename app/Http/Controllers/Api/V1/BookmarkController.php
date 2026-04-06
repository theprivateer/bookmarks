<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreBookmarkRequest;
use App\Http\Requests\Api\V1\UpdateBookmarkRequest;
use App\Http\Resources\BookmarkResource;
use App\Jobs\ProcessBookmark;
use App\Models\Bookmark;
use App\Models\Tag;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

class BookmarkController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(['q' => 'nullable|string|max:500']);

        $isSearching = $request->filled('q');

        $query = $request->user()
            ->bookmarks()
            ->with('tags')
            ->when(
                $request->query('tag'),
                fn ($q, $tag) => $q->whereHas('tags', fn ($t) => $t->where('slug', $tag))
            )
            ->when(
                $request->query('collection'),
                fn ($q, $slug) => $q->whereHas(
                    'collections',
                    fn ($c) => $c->where('slug', $slug)->where('user_id', $request->user()->id)
                )
            );

        $bookmarks = $isSearching
            ? $query->whereVectorSimilarTo('embedding', $request->query('q'), minSimilarity: 0.3)->simplePaginate(15)
            : $query->latest()->paginate(15);

        return BookmarkResource::collection($bookmarks);
    }

    public function store(StoreBookmarkRequest $request): BookmarkResource
    {
        $url = $request->validated('url');

        $bookmark = $request->user()->bookmarks()->create([
            'url' => $url,
            'domain' => parse_url($url, PHP_URL_HOST),
            'status' => 'pending',
        ]);

        ProcessBookmark::dispatch($bookmark->id);

        return BookmarkResource::make($bookmark);
    }

    public function show(Request $request, Bookmark $bookmark): BookmarkResource
    {
        abort_unless($bookmark->user_id === $request->user()->id, 404);

        $bookmark->load('tags', 'collections');

        return BookmarkResource::make($bookmark);
    }

    public function update(UpdateBookmarkRequest $request, int $id): BookmarkResource
    {
        $bookmark = Bookmark::withTrashed()
            ->where('user_id', $request->user()->id)
            ->findOrFail($id);

        $validated = $request->validated();

        // Handle archive/restore
        if (array_key_exists('archived', $validated)) {
            if ($validated['archived']) {
                $bookmark->delete();
            } else {
                $bookmark->restore();
            }
        }

        // Handle field updates
        $fields = array_intersect_key($validated, array_flip(['title', 'description', 'notes']));
        if ($fields) {
            $bookmark->update($fields);
        }

        // Sync tags
        if (array_key_exists('tags', $validated)) {
            $tagIds = collect($validated['tags'])->map(
                fn ($name) => Tag::firstOrCreate(
                    ['slug' => Str::slug($name)],
                    ['name' => $name],
                )->id
            );
            $bookmark->tags()->sync($tagIds);
        }

        // Sync collections (validate user owns them)
        if (array_key_exists('collection_ids', $validated)) {
            $validIds = $request->user()->collections()
                ->whereIn('id', $validated['collection_ids'])
                ->pluck('id');
            $bookmark->collections()->sync($validIds);
        }

        $bookmark->load('tags', 'collections');

        return BookmarkResource::make($bookmark->fresh());
    }

    public function destroy(Request $request, Bookmark $bookmark): Response
    {
        abort_unless($bookmark->user_id === $request->user()->id, 404);

        $bookmark->delete();

        return response()->noContent();
    }
}
