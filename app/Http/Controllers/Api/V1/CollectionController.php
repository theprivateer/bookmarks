<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\CollectionResource;
use App\Models\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

class CollectionController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $collections = $request->user()
            ->collections()
            ->withCount('bookmarks')
            ->orderBy('name')
            ->get();

        return CollectionResource::collection($collections);
    }

    public function store(Request $request): CollectionResource
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
        ]);

        $collection = $request->user()->collections()->create([
            'name' => $validated['name'],
            'slug' => Str::slug($validated['name']),
        ]);

        return CollectionResource::make($collection);
    }

    public function show(Request $request, Collection $collection): CollectionResource
    {
        abort_unless($collection->user_id === $request->user()->id, 404);

        $collection->load('bookmarks.tags');
        $collection->loadCount('bookmarks');

        return CollectionResource::make($collection);
    }

    public function update(Request $request, Collection $collection): CollectionResource
    {
        abort_unless($collection->user_id === $request->user()->id, 404);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
        ]);

        $collection->update([
            'name' => $validated['name'],
            'slug' => Str::slug($validated['name']),
        ]);

        return CollectionResource::make($collection);
    }

    public function destroy(Request $request, Collection $collection): Response
    {
        abort_unless($collection->user_id === $request->user()->id, 404);

        $collection->delete();

        return response()->noContent();
    }
}
