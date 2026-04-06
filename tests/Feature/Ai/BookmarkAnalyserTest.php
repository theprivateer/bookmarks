<?php

use App\Ai\Agents\BookmarkAnalyser;

test('agent returns structured output matching schema', function () {
    BookmarkAnalyser::fake();

    $response = (new BookmarkAnalyser)->prompt('Title: Laravel\n\nContent: Laravel is a PHP framework.');

    expect($response['summary'])->toBeString()
        ->and($response['tags'])->toBeArray();
});

test('agent can be faked with specific responses', function () {
    BookmarkAnalyser::fake([
        ['summary' => 'A great PHP framework.', 'tags' => ['laravel', 'php']],
    ]);

    $response = (new BookmarkAnalyser)->prompt('anything');

    expect($response['summary'])->toBe('A great PHP framework.')
        ->and($response['tags'])->toBe(['laravel', 'php']);
});
