<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Bookmark Analysis Source
    |--------------------------------------------------------------------------
    |
    | This value determines which extracted content column should be used
    | when generating bookmark summaries, tags, embeddings, and keyword
    | search matches. Supported values are "markdown_text" and
    | "extracted_text".
    |
    */

    'analysis_source_column' => env('BOOKMARKS_ANALYSIS_SOURCE_COLUMN', 'markdown_text'),

    /*
    |--------------------------------------------------------------------------
    | Markdown Extraction Service
    |--------------------------------------------------------------------------
    |
    | These options configure the external service used to request an
    | additional Markdown representation for each bookmarked page. The
    | default endpoint is markdown.new and the default method is "auto".
    |
    */

    'markdown_service' => [
        'url' => env('BOOKMARKS_MARKDOWN_SERVICE_URL', 'https://markdown.new/'),
        'method' => env('BOOKMARKS_MARKDOWN_SERVICE_METHOD', 'auto'),
    ],
];
