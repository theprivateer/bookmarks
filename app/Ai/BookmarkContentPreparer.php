<?php

namespace App\Ai;

use Illuminate\Support\Str;

class BookmarkContentPreparer
{
    /**
     * @return array{title: string|null, description: string|null, content: string}|null
     */
    public function prepare(?string $title, ?string $description, ?string $extractedText): ?array
    {
        $normalizedTitle = $this->normalizeInlineText($title);
        $normalizedDescription = $this->normalizeInlineText($description);
        $cleanedContent = $this->cleanContent($extractedText);

        if (blank($normalizedTitle) && blank($normalizedDescription) && blank($cleanedContent)) {
            return null;
        }

        return [
            'title' => $normalizedTitle,
            'description' => $normalizedDescription,
            'content' => $cleanedContent,
        ];
    }

    private function cleanContent(?string $content): ?string
    {
        if (blank($content)) {
            return null;
        }

        $paragraphs = preg_split('/\R\s*\R+/', str_replace(["\r\n", "\r"], "\n", $content)) ?: [];

        if ($paragraphs === []) {
            return null;
        }

        $seenParagraphs = [];
        $cleanedParagraphs = [];

        foreach ($paragraphs as $paragraph) {
            $normalizedParagraph = $this->normalizeParagraph($paragraph);

            if ($normalizedParagraph === null || $this->shouldDiscardParagraph($normalizedParagraph)) {
                continue;
            }

            $fingerprint = Str::lower($normalizedParagraph);
            $seenParagraphs[$fingerprint] = ($seenParagraphs[$fingerprint] ?? 0) + 1;

            if ($seenParagraphs[$fingerprint] > 1 && $this->isSafeToDeduplicate($normalizedParagraph)) {
                continue;
            }

            $cleanedParagraphs[] = $normalizedParagraph;
        }

        if ($cleanedParagraphs === []) {
            return null;
        }

        return implode("\n\n", $cleanedParagraphs);
    }

    private function normalizeInlineText(?string $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        $normalized = preg_replace('/\s+/u', ' ', trim($value));

        return blank($normalized) ? null : $normalized;
    }

    private function normalizeParagraph(string $paragraph): ?string
    {
        $normalized = preg_replace('/[ \t]+/u', ' ', trim($paragraph));

        if ($normalized === null || $normalized === '') {
            return null;
        }

        return preg_replace('/\n{3,}/', "\n\n", $normalized);
    }

    private function shouldDiscardParagraph(string $paragraph): bool
    {
        $urlCount = preg_match_all('/https?:\/\/|www\./iu', $paragraph) ?: 0;
        $separatorCount = preg_match_all('/\s(?:\||›|»|\/)\s/u', $paragraph) ?: 0;
        $wordCount = str_word_count(preg_replace('/[^\pL\pN\s-]+/u', ' ', $paragraph) ?? '');
        $letterCount = preg_match_all('/\pL/u', $paragraph) ?: 0;

        if ($letterCount < 8) {
            return true;
        }

        if ($urlCount >= 3 && $wordCount <= 40) {
            return true;
        }

        if ($separatorCount >= 3 && $wordCount <= 30) {
            return true;
        }

        return false;
    }

    private function isSafeToDeduplicate(string $paragraph): bool
    {
        $wordCount = str_word_count(preg_replace('/[^\pL\pN\s-]+/u', ' ', $paragraph) ?? '');
        $sentenceCount = preg_match_all('/[.!?]/u', $paragraph) ?: 0;

        return $wordCount <= 40 || $sentenceCount <= 1;
    }
}
