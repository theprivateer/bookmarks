<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Tag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TagController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tags = Tag::whereHas('bookmarks', fn ($q) => $q->where('user_id', $request->user()->id))
            ->withCount(['bookmarks' => fn ($q) => $q->where('user_id', $request->user()->id)])
            ->orderBy('name')
            ->get(['id', 'name', 'slug']);

        return response()->json(['data' => $tags]);
    }
}
