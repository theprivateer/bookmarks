<?php

use App\Jobs\ProcessBookmark;
use App\Models\Bookmark;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

function makeHtml(array $options = []): string
{
    $title = $options['title'] ?? 'Test Page Title';
    $ogTitle = $options['ogTitle'] ?? '';
    $description = $options['description'] ?? '';
    $ogDescription = $options['ogDescription'] ?? '';
    $ogImage = $options['ogImage'] ?? '';
    $favicon = $options['favicon'] ?? '';
    $body = $options['body'] ?? '<p>Some readable content for the page body text.</p>';

    $metas = '';
    if ($ogTitle) {
        $metas .= "<meta property=\"og:title\" content=\"{$ogTitle}\">";
    }
    if ($ogDescription) {
        $metas .= "<meta property=\"og:description\" content=\"{$ogDescription}\">";
    }
    if ($description) {
        $metas .= "<meta name=\"description\" content=\"{$description}\">";
    }
    if ($ogImage) {
        $metas .= "<meta property=\"og:image\" content=\"{$ogImage}\">";
    }
    if ($favicon) {
        $metas .= "<link rel=\"icon\" href=\"{$favicon}\">";
    }

    return <<<HTML
    <!DOCTYPE html>
    <html>
    <head>
        <title>{$title}</title>
        {$metas}
    </head>
    <body>{$body}</body>
    </html>
    HTML;
}

test('job extracts title from title tag', function () {
    $user = User::factory()->create();
    $bookmark = Bookmark::factory()->for($user)->create([
        'url' => 'https://example.com',
        'domain' => 'example.com',
        'status' => 'pending',
    ]);

    Http::fake(['https://example.com' => Http::response(makeHtml(['title' => 'My Test Page']))]);

    (new ProcessBookmark($bookmark->id))->handle();

    expect($bookmark->fresh()->title)->toBe('My Test Page')
        ->and($bookmark->fresh()->status)->toBe('processed');
});

test('job extracts og:description and falls back to meta description', function () {
    $user = User::factory()->create();
    $bookmark = Bookmark::factory()->for($user)->create([
        'url' => 'https://example.com',
        'domain' => 'example.com',
        'status' => 'pending',
    ]);

    Http::fake(['https://example.com' => Http::response(makeHtml([
        'ogDescription' => 'OG description here',
    ]))]);

    (new ProcessBookmark($bookmark->id))->handle();

    expect($bookmark->fresh()->description)->toBe('OG description here');
});

test('job falls back to meta description when no og:description', function () {
    $user = User::factory()->create();
    $bookmark = Bookmark::factory()->for($user)->create([
        'url' => 'https://example.com',
        'domain' => 'example.com',
        'status' => 'pending',
    ]);

    Http::fake(['https://example.com' => Http::response(makeHtml([
        'description' => 'Meta description here',
    ]))]);

    (new ProcessBookmark($bookmark->id))->handle();

    expect($bookmark->fresh()->description)->toBe('Meta description here');
});

test('job extracts og:image and resolves relative urls', function () {
    $user = User::factory()->create();
    $bookmark = Bookmark::factory()->for($user)->create([
        'url' => 'https://example.com/page',
        'domain' => 'example.com',
        'status' => 'pending',
    ]);

    Http::fake(['https://example.com/page' => Http::response(makeHtml([
        'ogImage' => 'https://example.com/image.jpg',
    ]))]);

    (new ProcessBookmark($bookmark->id))->handle();

    expect($bookmark->fresh()->og_image_url)->toBe('https://example.com/image.jpg');
});

test('job falls back to google favicon when no icon link in html', function () {
    $user = User::factory()->create();
    $bookmark = Bookmark::factory()->for($user)->create([
        'url' => 'https://example.com',
        'domain' => 'example.com',
        'status' => 'pending',
    ]);

    Http::fake(['https://example.com' => Http::response(makeHtml())]);

    (new ProcessBookmark($bookmark->id))->handle();

    expect($bookmark->fresh()->favicon_url)->toBe('https://www.google.com/s2/favicons?domain=example.com&sz=64');
});

test('job uses html favicon link when present', function () {
    $user = User::factory()->create();
    $bookmark = Bookmark::factory()->for($user)->create([
        'url' => 'https://example.com',
        'domain' => 'example.com',
        'status' => 'pending',
    ]);

    Http::fake(['https://example.com' => Http::response(makeHtml([
        'favicon' => '/favicon.ico',
    ]))]);

    (new ProcessBookmark($bookmark->id))->handle();

    expect($bookmark->fresh()->favicon_url)->toBe('https://example.com/favicon.ico');
});

test('job sets status to processed on success', function () {
    $user = User::factory()->create();
    $bookmark = Bookmark::factory()->for($user)->create([
        'url' => 'https://example.com',
        'domain' => 'example.com',
        'status' => 'pending',
    ]);

    Http::fake(['https://example.com' => Http::response(makeHtml())]);

    (new ProcessBookmark($bookmark->id))->handle();

    expect($bookmark->fresh()->status)->toBe('processed');
});

test('failed method sets status to failed', function () {
    $user = User::factory()->create();
    $bookmark = Bookmark::factory()->for($user)->create(['status' => 'pending']);

    $job = new ProcessBookmark($bookmark->id);
    $job->failed(new Exception('Connection timeout'));

    expect($bookmark->fresh()->status)->toBe('failed');
});

test('job handles minimal html gracefully', function () {
    $user = User::factory()->create();
    $bookmark = Bookmark::factory()->for($user)->create([
        'url' => 'https://example.com',
        'domain' => 'example.com',
        'status' => 'pending',
    ]);

    Http::fake(['https://example.com' => Http::response('<html><body>Hello</body></html>')]);

    (new ProcessBookmark($bookmark->id))->handle();

    expect($bookmark->fresh()->status)->toBe('processed');
});

test('job is dispatched when bookmark created via api', function () {
    Queue::fake();

    $user = User::factory()->create();
    $token = $user->createToken('test')->plainTextToken;

    $this->withToken($token)
        ->postJson('/api/v1/bookmarks', ['url' => 'https://example.com'])
        ->assertStatus(201);

    Queue::assertPushed(ProcessBookmark::class, function ($job) {
        return $job->bookmarkId === Bookmark::latest()->first()->id;
    });
});
