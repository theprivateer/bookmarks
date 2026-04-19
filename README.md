# Bookmarks

An AI-powered bookmark manager built with Laravel 13, Livewire 4, and Flux UI. Save URLs, automatically extract content and generate summaries, search semantically, and chat with your bookmarks using natural language.

## Features

- **Bookmark management** -- Save URLs with automatic metadata extraction (title, description, OG image, favicon, extracted text, Markdown content)
- **AI analysis** -- Generates summaries and tags for each bookmark using OpenAI (via Laravel AI SDK)
- **Semantic search** -- Find bookmarks by meaning using pgvector embeddings, not just keywords
- **AI chat** -- Conversational interface to ask questions about your saved bookmarks (RAG-powered)
- **Collections** -- Organise bookmarks into user-defined collections with sidebar navigation
- **Tagging** -- Auto-generated tags with manual editing, filter bookmarks by tag
- **Bookmark editing** -- Edit title, description, personal notes, tags, and collection assignments
- **REST API** -- Full API with Sanctum authentication for bookmarks, tags, and collections
- **Grid and list views** -- Toggle between card grid and compact list layouts

## Tech Stack

- **Backend:** PHP 8.4, Laravel 13
- **Frontend:** Livewire 4, Flux UI Pro v2, Tailwind CSS v4
- **AI:** Laravel AI SDK (`laravel/ai`) with OpenAI (GPT-4o for chat, structured output for analysis)
- **Database:** PostgreSQL 17 with pgvector extension
- **Build:** Vite 8
- **Testing:** Pest 4

## Prerequisites

- PHP 8.4+
- PostgreSQL 17+ with the `vector` extension enabled
- Node.js and npm
- Composer

## Setup

```bash
composer setup
```

This runs `composer install`, copies `.env.example` to `.env`, generates an app key, runs migrations, installs npm dependencies, and builds frontend assets.

### Environment

Copy `.env.example` to `.env` and configure:

- **Database** -- PostgreSQL connection (`DB_CONNECTION=pgsql`)
- **OpenAI** -- Set `OPENAI_API_KEY` for AI analysis and chat features
- **Queue** -- Configure a queue driver (e.g. `database`, `redis`) for background processing
- **Bookmark analysis source** -- Choose which extracted content is used for AI analysis and keyword search via `BOOKMARKS_ANALYSIS_SOURCE_COLUMN` (`markdown_text` by default)
- **Markdown extraction service** -- Optionally override `BOOKMARKS_MARKDOWN_SERVICE_URL` or `BOOKMARKS_MARKDOWN_SERVICE_METHOD` if you need to target a different markdown extraction endpoint or extraction mode

### Development

```bash
composer run dev
```

Starts the web server, queue worker, log tail (Pail), and Vite dev server concurrently.

## Architecture

### Processing Pipeline

1. User submits a URL via the web UI or API
2. `ProcessBookmark` job fetches the page, extracts metadata and readable text (using Readability.php), then requests an additional Markdown representation from `markdown.new`
3. `AnalyseBookmark` job reads the configured source column (`markdown_text` by default, or `extracted_text`) for summary and tag generation, then generates a vector embedding
4. Bookmark is searchable by semantic similarity and available for AI chat

### Content Sources

Each bookmark can store two extracted content representations:

- `extracted_text` -- Readability-based plain text extraction from the fetched HTML
- `markdown_text` -- Markdown returned by the external markdown extraction service

The `BOOKMARKS_ANALYSIS_SOURCE_COLUMN` setting controls which column is used for:

- AI summary generation
- AI tag generation
- Embedding generation
- Keyword search matching

The default is `markdown_text`.

### Bookmark Statuses

| Status | Description |
|---|---|
| `pending` | URL saved, waiting for content extraction |
| `processed` | Content extracted and AI analysis complete |
| `failed` | Content extraction failed |
| `analysis_failed` | Content extracted but AI analysis failed (retryable) |

### AI Agents

- **BookmarkAnalyser** -- Structured output agent that generates a summary and up to 5 tags from page content
- **BookmarkChat** -- Conversational agent with RAG tool access to search the user's bookmarks by semantic similarity

### Models

- **User** -- Authentication, owns bookmarks and collections
- **Bookmark** -- URL, metadata, extracted text, markdown text, AI summary, vector embedding, status
- **Tag** -- Shared across users, linked via pivot table
- **Collection** -- User-scoped folders for organising bookmarks

## API

All API routes are under `/api/v1/` and require Sanctum authentication.

### Bookmarks

| Method | Endpoint | Description |
|---|---|---|
| GET | `/api/v1/bookmarks` | List bookmarks (supports `tag`, `collection`, `status` filters) |
| POST | `/api/v1/bookmarks` | Create a bookmark |
| GET | `/api/v1/bookmarks/{id}` | Show a bookmark with tags and collections |
| PUT | `/api/v1/bookmarks/{id}` | Update title, description, notes, tags, collections, archived status |
| DELETE | `/api/v1/bookmarks/{id}` | Soft-delete a bookmark |

### Collections

| Method | Endpoint | Description |
|---|---|---|
| GET | `/api/v1/collections` | List collections with bookmark counts |
| POST | `/api/v1/collections` | Create a collection |
| GET | `/api/v1/collections/{id}` | Show a collection with its bookmarks |
| PUT | `/api/v1/collections/{id}` | Rename a collection |
| DELETE | `/api/v1/collections/{id}` | Delete a collection |

### Tags

| Method | Endpoint | Description |
|---|---|---|
| GET | `/api/v1/tags` | List tags for the authenticated user's bookmarks |

## Testing

```bash
php artisan test
```

The test suite covers bookmark CRUD, the extraction and AI analysis pipeline, semantic and keyword search, chat, collections, tag management, and API endpoints.

## Maintenance

Recalculate bookmark summaries, tags, and embeddings using the currently configured analysis source:

```bash
php artisan bookmarks:reanalyse
```

This command queues `AnalyseBookmark` for every bookmark that has content in the configured source column and skips bookmarks that do not.

## Code Style

```bash
vendor/bin/pint
```

Uses Laravel Pint for PHP code formatting.
