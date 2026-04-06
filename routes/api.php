<?php

use App\Http\Controllers\Api\V1\BookmarkController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::prefix('v1')->middleware('auth:sanctum')->group(function () {
    Route::apiResource('bookmarks', BookmarkController::class)
        ->only(['index', 'store', 'show', 'destroy']);
});
