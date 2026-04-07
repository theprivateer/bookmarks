<?php

namespace App\Ai;

class ParagraphChunker
{
    /**
     * @return list<string>
     */
    public function chunk(
        string $content,
        int $maxCharacters,
        int $overlapCharacters = 0,
        int $maxChunks = 1
    ): array {
        $segments = $this->segments($content, $maxCharacters);

        if ($segments === []) {
            return [];
        }

        $chunks = [];
        $index = 0;
        $totalSegments = count($segments);

        while ($index < $totalSegments && count($chunks) < $maxChunks) {
            $chunkSegments = [];
            $chunkLength = 0;
            $startIndex = $index;

            while ($index < $totalSegments) {
                $segment = $segments[$index];
                $segmentLength = mb_strlen($segment);
                $candidateLength = $chunkLength === 0 ? $segmentLength : $chunkLength + 2 + $segmentLength;

                if ($chunkSegments !== [] && $candidateLength > $maxCharacters) {
                    break;
                }

                $chunkSegments[] = $segment;
                $chunkLength = $candidateLength;
                $index++;
            }

            if ($chunkSegments === []) {
                break;
            }

            $chunks[] = implode("\n\n", $chunkSegments);

            if ($index >= $totalSegments) {
                break;
            }

            if ($overlapCharacters <= 0) {
                continue;
            }

            $rewindCharacters = 0;
            $rewindCount = 0;

            for ($segmentIndex = count($chunkSegments) - 1; $segmentIndex >= 0; $segmentIndex--) {
                $rewindCharacters += mb_strlen($chunkSegments[$segmentIndex]) + 2;
                $rewindCount++;

                if ($rewindCharacters >= $overlapCharacters) {
                    break;
                }
            }

            $index = max($startIndex + 1, $index - $rewindCount);
        }

        return $chunks;
    }

    /**
     * @return list<string>
     */
    private function segments(string $content, int $maxCharacters): array
    {
        $normalized = trim(str_replace(["\r\n", "\r"], "\n", $content));

        if ($normalized === '') {
            return [];
        }

        $paragraphs = preg_split('/\n\s*\n+/', $normalized) ?: [];
        $segments = [];

        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);

            if ($paragraph === '') {
                continue;
            }

            if (mb_strlen($paragraph) <= $maxCharacters) {
                $segments[] = $paragraph;

                continue;
            }

            foreach ($this->splitLongSegment($paragraph, $maxCharacters) as $segment) {
                $segments[] = $segment;
            }
        }

        return $segments;
    }

    /**
     * @return list<string>
     */
    private function splitLongSegment(string $segment, int $maxCharacters): array
    {
        $parts = preg_split('/(?<=[.!?])\s+/u', $segment, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if (count($parts) <= 1) {
            return $this->splitByWords($segment, $maxCharacters);
        }

        $segments = [];
        $buffer = '';

        foreach ($parts as $part) {
            $part = trim($part);

            if ($part === '') {
                continue;
            }

            $candidate = $buffer === '' ? $part : $buffer.' '.$part;

            if (mb_strlen($candidate) <= $maxCharacters) {
                $buffer = $candidate;

                continue;
            }

            if ($buffer !== '') {
                $segments[] = $buffer;
            }

            if (mb_strlen($part) <= $maxCharacters) {
                $buffer = $part;

                continue;
            }

            foreach ($this->splitByWords($part, $maxCharacters) as $wordChunk) {
                $segments[] = $wordChunk;
            }

            $buffer = '';
        }

        if ($buffer !== '') {
            $segments[] = $buffer;
        }

        return $segments;
    }

    /**
     * @return list<string>
     */
    private function splitByWords(string $segment, int $maxCharacters): array
    {
        $words = preg_split('/\s+/u', trim($segment), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $segments = [];
        $buffer = '';

        foreach ($words as $word) {
            if (mb_strlen($word) > $maxCharacters) {
                if ($buffer !== '') {
                    $segments[] = $buffer;
                    $buffer = '';
                }

                $segments = [...$segments, ...mb_str_split($word, $maxCharacters)];

                continue;
            }

            $candidate = $buffer === '' ? $word : $buffer.' '.$word;

            if (mb_strlen($candidate) <= $maxCharacters) {
                $buffer = $candidate;

                continue;
            }

            $segments[] = $buffer;
            $buffer = $word;
        }

        if ($buffer !== '') {
            $segments[] = $buffer;
        }

        return $segments;
    }
}
