<?php

use App\Ai\Agents\BookmarkChat;
use App\Models\User;
use Laravel\Ai\Tools\SimilaritySearch;

test('agent can be faked and returns text response', function () {
    BookmarkChat::fake(['Here are your bookmarks about Laravel...']);

    $user = User::factory()->create();

    $response = (new BookmarkChat)->forUser($user)->prompt('What bookmarks do I have about Laravel?');

    expect($response->text)->toBe('Here are your bookmarks about Laravel...');
});

test('agent defines the similarity search tool', function () {
    $user = User::factory()->create();

    $agent = (new BookmarkChat)->forUser($user);
    $tools = collect($agent->tools());

    expect($tools)->toHaveCount(1)
        ->and($tools->first())->toBeInstanceOf(SimilaritySearch::class);
});

test('agent is never prompted in isolation', function () {
    BookmarkChat::fake()->preventStrayPrompts();

    BookmarkChat::assertNeverPrompted();
});
