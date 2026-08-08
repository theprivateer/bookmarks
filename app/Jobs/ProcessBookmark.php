<?php

namespace App\Jobs;

use App\Models\Bookmark;
use App\Rules\PublicHttpUrl;
use fivefilters\Readability\Configuration;
use fivefilters\Readability\ParseException;
use fivefilters\Readability\Readability;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ProcessBookmark implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * Must exceed the worst-case outbound budget, which is ~30s: the page fetch
     * (10s) plus the markdown service's two attempts (10s each, 200ms apart). At
     * the previous 30s the job could be killed mid-flight and retried three times
     * before being marked failed, even when the page itself fetched cleanly.
     */
    public int $timeout = 60;

    public function __construct(public int $bookmarkId)
    {
        $this->afterCommit();
    }

    public function backoff(): array
    {
        return [5, 15, 30];
    }

    public function handle(): void
    {
        $bookmark = Bookmark::findOrFail($this->bookmarkId);

        // Re-checked here and not only at validation time: a URL can be re-queued
        // by bookmarks:refetch long after it was saved, and DNS may have changed
        // under it in the meantime.
        if (! PublicHttpUrl::isFetchable($bookmark->url)) {
            Log::warning('ProcessBookmark refused to fetch a non-public URL', [
                'bookmark_id' => $bookmark->id,
                'bookmark_url' => $bookmark->url,
            ]);

            $bookmark->update(['status' => 'failed']);

            return;
        }

        $response = Http::timeout(10)
            ->connectTimeout(5)
            ->withUserAgent('Mozilla/5.0 (compatible; Bookmarks/1.0)')
            ->withOptions([
                // A public host is free to redirect to a private one, so every hop
                // is re-checked. Throwing here aborts the transfer.
                'allow_redirects' => [
                    'max' => 5,
                    'strict' => true,
                    'referer' => false,
                    'protocols' => ['http', 'https'],
                    'on_redirect' => function ($request, $response, $uri): void {
                        if (! PublicHttpUrl::isFetchable((string) $uri)) {
                            throw new RuntimeException("Redirect to a non-public URL was blocked: {$uri}");
                        }
                    },
                ],
            ])
            ->get($bookmark->url);

        // Without this guard an error page becomes the input: every extractor
        // returns null and the update below overwrites good metadata with nulls.
        // bookmarks:refetch makes that a bulk data-loss event across dead links.
        if ($response->failed()) {
            Log::warning('ProcessBookmark received an unsuccessful response', [
                'bookmark_id' => $bookmark->id,
                'status_code' => $response->status(),
            ]);

            // A 5xx is often transient, so let the queue retry it. A 4xx will not
            // fix itself, so record the link as dead and keep the existing metadata.
            if ($response->serverError()) {
                throw new RuntimeException("Fetch failed with status {$response->status()}");
            }

            $bookmark->update(['status' => 'failed']);

            return;
        }

        $html = $response->body();

        // Real-world HTML is frequently malformed; internal error capture prevents
        // libxml warnings from being emitted to output or logs before we can clear them.
        libxml_use_internal_errors(true);
        $doc = new \DOMDocument;
        $doc->loadHTML('<?xml encoding="utf-8" ?>'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();

        $title = $this->extractTitle($doc);
        $description = $this->extractDescription($doc);
        $ogImage = $this->extractOgImage($doc, $bookmark->url);
        $favicon = $this->extractFavicon($doc, $bookmark->url, $bookmark->domain);
        $extractedText = $this->extractReadableText($html);
        $markdownText = $this->extractMarkdown($bookmark->url);

        $bookmark->update([
            'title' => $title,
            'description' => $description,
            'og_image_url' => $ogImage,
            'favicon_url' => $favicon,
            'extracted_text' => $extractedText === null ? null : trim($extractedText),
            'markdown_text' => $markdownText,
            'status' => 'processed',
        ]);

        AnalyseBookmark::dispatch($bookmark->id);
    }

    public function failed(?Throwable $exception): void
    {
        Bookmark::where('id', $this->bookmarkId)->update(['status' => 'failed']);
    }

    private function extractTitle(\DOMDocument $doc): ?string
    {
        $titleTags = $doc->getElementsByTagName('title');
        if ($titleTags->length > 0) {
            $title = trim($titleTags->item(0)->textContent);
            if ($title !== '') {
                return $title;
            }
        }

        return $this->extractMetaContent($doc, 'og:title', 'property');
    }

    private function extractDescription(\DOMDocument $doc): ?string
    {
        $ogDescription = $this->extractMetaContent($doc, 'og:description', 'property');
        if ($ogDescription !== null) {
            return $ogDescription;
        }

        return $this->extractMetaContent($doc, 'description', 'name');
    }

    private function extractOgImage(\DOMDocument $doc, string $pageUrl): ?string
    {
        $ogImage = $this->extractMetaContent($doc, 'og:image', 'property');

        if ($ogImage !== null) {
            return $this->resolveUrl($ogImage, $pageUrl);
        }

        return null;
    }

    private function extractFavicon(\DOMDocument $doc, string $pageUrl, ?string $domain): string
    {
        $xpath = new \DOMXPath($doc);
        $links = $xpath->query('//link[contains(translate(@rel, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "icon")]');

        foreach ($links as $link) {
            $href = $link->getAttribute('href');
            if ($href !== '') {
                return $this->resolveUrl($href, $pageUrl);
            }
        }

        return "https://www.google.com/s2/favicons?domain={$domain}&sz=64";
    }

    private function extractReadableText(string $html): ?string
    {
        try {
            $readability = new Readability(new Configuration);
            $readability->parse($html);

            $content = $readability->getContent();

            if ($content === null || $content === '') {
                return null;
            }

            return strip_tags($content);
        } catch (ParseException) {
            return null;
        }
    }

    private function extractMarkdown(string $url): ?string
    {
        try {
            $response = Http::timeout(10)
                ->connectTimeout(5)
                ->retry(2, 200, throw: false)
                ->post(config('bookmarks.markdown_service.url'), [
                    'url' => $url,
                    'method' => config('bookmarks.markdown_service.method', 'auto'),
                ]);

            if (! $response->successful()) {
                Log::warning('Markdown extraction failed for bookmark', [
                    'bookmark_url' => $url,
                    'status_code' => $response->status(),
                    // Truncated: the service can return a full HTML error page, and
                    // this fires once per bookmark on a bad day.
                    'response_body' => Str::limit($response->body(), 500),
                ]);

                return null;
            }

            $markdown = $response->json('content');

            if (! is_string($markdown)) {
                return null;
            }

            $markdown = trim($markdown);

            return $markdown !== '' ? $markdown : null;
        } catch (Throwable $exception) {
            Log::warning('Markdown extraction failed for bookmark', [
                'bookmark_url' => $url,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    private function extractMetaContent(\DOMDocument $doc, string $value, string $attribute): ?string
    {
        $xpath = new \DOMXPath($doc);
        $nodes = $xpath->query("//meta[@{$attribute}='{$value}']");

        if ($nodes !== false && $nodes->length > 0) {
            $content = trim($nodes->item(0)->getAttribute('content'));

            return $content !== '' ? $content : null;
        }

        return null;
    }

    /**
     * Converts a URL found in HTML to an absolute URL relative to the page it came from.
     * Handles four forms: absolute (pass-through), protocol-relative (//example.com),
     * root-relative (/path), and document-relative (../path or just file.html).
     */
    private function resolveUrl(string $url, string $base): string
    {
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        $parsed = parse_url($base);
        $scheme = $parsed['scheme'] ?? 'https';
        $host = $parsed['host'] ?? '';

        if (str_starts_with($url, '//')) {
            return $scheme.':'.$url;
        }

        if (str_starts_with($url, '/')) {
            return $scheme.'://'.$host.$url;
        }

        // Document-relative: resolve against the directory of the base page, not its full path.
        $path = isset($parsed['path']) ? dirname($parsed['path']).'/' : '/';

        return $scheme.'://'.$host.$path.$url;
    }
}
