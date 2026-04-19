<?php

namespace App\Jobs;

use App\Models\Bookmark;
use fivefilters\Readability\Configuration;
use fivefilters\Readability\ParseException;
use fivefilters\Readability\Readability;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessBookmark implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

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

        $response = Http::timeout(10)
            ->connectTimeout(5)
            ->withUserAgent('Mozilla/5.0 (compatible; Bookmarks/1.0)')
            ->maxRedirects(5)
            ->get($bookmark->url);

        $html = $response->body();

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
            'extracted_text' => trim($extractedText),
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
                    'response_body' => $response->body(),
                ]);

                return null;
            }

            $markdown = trim($response->body());

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

        $path = isset($parsed['path']) ? dirname($parsed['path']).'/' : '/';

        return $scheme.'://'.$host.$path.$url;
    }
}
