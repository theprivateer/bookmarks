<?php

use App\Jobs\ProcessBookmark;
use App\Livewire\Header\AddBookmark;
use App\Models\Bookmark;
use App\Models\User;
use App\Rules\PublicHttpUrl;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;

test('rejects urls pointing at private, loopback and reserved addresses', function (string $url) {
    expect(PublicHttpUrl::isFetchable($url))->toBeFalse();
})->with([
    'loopback' => 'http://127.0.0.1:5432/',
    'loopback ipv6' => 'http://[::1]/',
    'ipv4 mapped loopback' => 'http://[::ffff:127.0.0.1]/',
    'cloud metadata' => 'http://169.254.169.254/latest/meta-data/',
    'private class a' => 'http://10.0.0.1/',
    'private class b' => 'http://172.16.0.1/',
    'private class c' => 'http://192.168.1.1/admin',
    'unspecified' => 'http://0.0.0.0/',
    'carrier grade nat' => 'http://100.64.0.1/',
    'unique local ipv6' => 'http://[fc00::1]/',
    'link local ipv6' => 'http://[fe80::1]/',
]);

test('rejects non http schemes', function (string $url) {
    expect(PublicHttpUrl::isFetchable($url))->toBeFalse();
})->with([
    'gopher' => 'gopher://127.0.0.1:11211/',
    'file' => 'file:///etc/passwd',
    'ftp' => 'ftp://example.com/secret',
    'no scheme' => 'example.com',
    'no host' => 'http://',
]);

test('accepts public http and https urls', function (string $url) {
    expect(PublicHttpUrl::isFetchable($url))->toBeTrue();
})->with([
    'https' => 'https://example.com/article',
    'http' => 'http://example.com/article',
    'public literal ip' => 'http://93.184.216.34/',
    'public ipv6 literal' => 'http://[2606:2800:220:1:248:1893:25c8:1946]/',
]);

test('rejects a host that does not resolve', function () {
    PublicHttpUrl::resolveUsing(fn (string $host): array => []);

    expect(PublicHttpUrl::isFetchable('https://does-not-resolve.example/'))->toBeFalse();
});

test('rejects a host where any resolved address is private', function () {
    PublicHttpUrl::resolveUsing(fn (string $host): array => ['93.184.216.34', '127.0.0.1']);

    expect(PublicHttpUrl::isFetchable('https://split-horizon.example/'))->toBeFalse();
});

test('api rejects a bookmark pointing at the cloud metadata endpoint', function () {
    Queue::fake();

    Sanctum::actingAs(User::factory()->create());

    $this->postJson('/api/v1/bookmarks', [
        'url' => 'http://169.254.169.254/latest/meta-data/',
    ])->assertJsonValidationErrorFor('url');

    Queue::assertNothingPushed();
});

test('add bookmark component rejects a loopback url', function () {
    Queue::fake();

    $this->actingAs(User::factory()->create());

    Livewire::test(AddBookmark::class)
        ->set('newUrl', 'http://127.0.0.1:5432/')
        ->call('addBookmark')
        ->assertHasErrors('newUrl');

    Queue::assertNothingPushed();
    expect(Bookmark::count())->toBe(0);
});

test('process bookmark blocks a public url redirecting to a private one', function () {
    Queue::fake();

    Http::fake([
        'https://example.com' => Http::response('', 302, [
            'Location' => 'http://169.254.169.254/latest/meta-data/',
        ]),
        'http://169.254.169.254/*' => Http::response('SECRET METADATA', 200),
    ]);

    $bookmark = Bookmark::factory()->for(User::factory())->create([
        'url' => 'https://example.com',
        'domain' => 'example.com',
        'status' => 'pending',
    ]);

    expect(fn () => (new ProcessBookmark($bookmark->id))->handle())
        ->toThrow(RuntimeException::class, 'Redirect to a non-public URL was blocked');

    expect($bookmark->fresh()->extracted_text)->toBeNull();
});

test('process bookmark refuses to fetch a url that is no longer public', function () {
    Queue::fake();
    Http::fake();

    $bookmark = Bookmark::factory()->for(User::factory())->create([
        'url' => 'http://169.254.169.254/latest/meta-data/',
        'domain' => '169.254.169.254',
        'status' => 'pending',
    ]);

    (new ProcessBookmark($bookmark->id))->handle();

    Http::assertNothingSent();
    expect($bookmark->fresh()->status)->toBe('failed');
});
