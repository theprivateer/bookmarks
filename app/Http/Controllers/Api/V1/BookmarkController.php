<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreBookmarkRequest;
use App\Http\Requests\Api\V1\UpdateBookmarkRequest;
use App\Http\Resources\BookmarkResource;
use App\Jobs\ProcessBookmark;
use App\Models\Bookmark;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class BookmarkController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $bookmarks = $request->user()
            ->bookmarks()
            ->with('tags')
            ->when(
                $request->query('tag'),
                fn ($q, $tag) => $q->whereHas('tags', fn ($t) => $t->where('slug', $tag))
            )
            ->latest()
            ->paginate(15);

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

        $bookmark->load('tags');

        return BookmarkResource::make($bookmark);
    }

    public function update(UpdateBookmarkRequest $request, int $id): BookmarkResource
    {
        $bookmark = Bookmark::withTrashed()
            ->where('user_id', $request->user()->id)
            ->findOrFail($id);

        if ($request->validated('archived')) {
            $bookmark->delete();
        } else {
            $bookmark->restore();
        }

        return BookmarkResource::make($bookmark->fresh());
    }

    public function destroy(Request $request, Bookmark $bookmark): Response
    {
        abort_unless($bookmark->user_id === $request->user()->id, 404);

        $bookmark->delete();

        return response()->noContent();
    }
}
