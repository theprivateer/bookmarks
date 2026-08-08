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
    Queue::fake();

    $user = User::factory()->create();
    $bookmark = Bookmark::factory()->for($user)->create([
        'url' => 'https://example.com',
        'domain' => 'example.com',
        'status' => 'pending',
    ]);

    Http::fake([
        'https://example.com' => Http::response(makeHtml(['title' => 'My Test Page'])),
        'https://markdown.new/' => Http::response(['content' => "# My Test Page\n\nMarkdown body"]),
    ]);

    (new ProcessBookmark($bookmark->id))->handle();

    expect($bookmark->fresh()->title)->toBe('My Test Page')
        ->and($bookmark->fresh()->status)->toBe('processed');
});

test('job extracts og:description and falls back to meta description', function () {
    Queue::fake();

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
    Queue::fake();

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
    Queue::fake();

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
    Queue::fake();

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
    Queue::fake();

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
    Queue::fake();

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
    Queue::fake();

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

test('job stores markdown text from markdown service', function () {
    Queue::fake();

    $user = User::factory()->create();
    $bookmark = Bookmark::factory()->for($user)->create([
        'url' => 'https://example.com',
        'domain' => 'example.com',
        'status' => 'pending',
    ]);

    Http::fake([
        'https://example.com' => Http::response(makeHtml(['title' => 'Markdown Page'])),
        'https://markdown.new/' => Http::response([
            'content' => "# Markdown Page\n\nAgent friendly content.",
            'title' => 'Markdown Page',
        ]),
    ]);

    (new ProcessBookmark($bookmark->id))->handle();

    expect($bookmark->fresh()->markdown_text)->toBe("# Markdown Page\n\nAgent friendly content.");
});

test('job continues when markdown service fails', function () {
    Queue::fake();

    $user = User::factory()->create();
    $bookmark = Bookmark::factory()->for($user)->create([
        'url' => 'https://example.com',
        'domain' => 'example.com',
        'status' => 'pending',
    ]);

    // Readability returns null for trivial markup, so the body has to be long
    // enough to be treated as article content for this assertion to mean anything.
    $body = '<article><p>'.str_repeat(
        'This is a substantial paragraph of article prose that Readability should treat as real body content. ',
        12,
    ).'</p></article>';

    Http::fake([
        'https://example.com' => Http::response(makeHtml(['title' => 'Fallback Page', 'body' => $body])),
        'https://markdown.new/' => Http::response('upstream error', 500),
    ]);

    (new ProcessBookmark($bookmark->id))->handle();

    expect($bookmark->fresh()->title)->toBe('Fallback Page')
        ->and($bookmark->fresh()->extracted_text)->toContain('substantial paragraph of article prose')
        ->and($bookmark->fresh()->markdown_text)->toBeNull()
        ->and($bookmark->fresh()->status)->toBe('processed');
});

test('job preserves existing metadata when the page returns a 404', function () {
    Queue::fake();

    $user = User::factory()->create();
    $bookmark = Bookmark::factory()->for($user)->processed()->create([
        'url' => 'https://example.com/gone',
        'domain' => 'example.com',
        'title' => 'Original Title',
        'description' => 'Original description',
        'extracted_text' => 'Original body text.',
    ]);

    Http::fake(['https://example.com/gone' => Http::response('<h1>Not Found</h1>', 404)]);

    (new ProcessBookmark($bookmark->id))->handle();

    $fresh = $bookmark->fresh();

    expect($fresh->title)->toBe('Original Title')
        ->and($fresh->description)->toBe('Original description')
        ->and($fresh->extracted_text)->toBe('Original body text.')
        ->and($fresh->status)->toBe('failed');
});

test('job retries rather than clobbering metadata on a server error', function () {
    Queue::fake();

    $user = User::factory()->create();
    $bookmark = Bookmark::factory()->for($user)->processed()->create([
        'url' => 'https://example.com/broken',
        'domain' => 'example.com',
        'title' => 'Original Title',
    ]);

    Http::fake(['https://example.com/broken' => Http::response('oops', 503)]);

    expect(fn () => (new ProcessBookmark($bookmark->id))->handle())
        ->toThrow(RuntimeException::class, 'Fetch failed with status 503');

    expect($bookmark->fresh()->title)->toBe('Original Title');
});

test('job posts expected payload to markdown service', function () {
    Queue::fake();

    $user = User::factory()->create();
    $bookmark = Bookmark::factory()->for($user)->create([
        'url' => 'https://example.com',
        'domain' => 'example.com',
        'status' => 'pending',
    ]);

    Http::fake([
        'https://example.com' => Http::response(makeHtml()),
        'https://markdown.new/' => Http::response('# Content'),
    ]);

    (new ProcessBookmark($bookmark->id))->handle();

    Http::assertSent(function ($request) {
        return $request->url() === 'https://markdown.new/'
            && $request['url'] === 'https://example.com'
            && $request['method'] === 'auto';
    });
});
