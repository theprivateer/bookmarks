# Bookmarks

An AI-powered bookmark manager for a single user. Save a URL and the app fetches the page in the background, extracts its metadata and readable content, writes a summary, assigns tags, and stores a vector embedding — so you can find things later by meaning rather than by remembering the exact title. Browser bookmarks are a graveyard; this makes them searchable and conversational.

Built with Laravel 13, Livewire 4, and Flux UI Pro, backed by PostgreSQL with pgvector. Everything the web UI does is also available over a versioned REST API, so mobile apps and browser extensions can be built against it as separate projects.

## Features

- **Save a URL** — from the header form in the web UI or `POST /api/v1/bookmarks`. Saving is instant; extraction and AI analysis run as queued jobs. Re-saving a URL you already have returns the existing bookmark instead of paying for a second fetch, and un-archives it if it was archived.
- **Automatic content extraction** — title, description, OG image, and favicon from the page's markup, readable body text via `fivefilters/readability.php`, plus a Markdown representation from an external extraction service (`markdown.new` by default).
- **AI summaries and tags** — the `BookmarkAnalyser` agent produces a 2–3 sentence summary and up to 5 lowercase tags via OpenAI structured output. Long pages are split into chunks, analysed independently, then merged by the `BookmarkAnalysisSynthesizer` agent.
- **Semantic + keyword search** — one search box runs an ILIKE scan and a pgvector similarity scan, merges the results with exact matches ranked first, and paginates them. Available in the UI and as `?q=` on the bookmarks index.
- **AI chat** — the `BookmarkChat` agent (GPT-4o) answers questions about your library, using a similarity-search tool scoped to your own bookmarks. Responses stream token by token and conversations persist across turns.
- **Collections** — user-scoped folders, managed from the sidebar, with bookmark counts. Filtering by collection is reflected in the URL (`?collection=slug`) so it survives a refresh.
- **Tagging** — auto-generated tags, editable per bookmark, clickable as filters.
- **Bookmark editing** — title, description, personal notes, tags, and collection membership.
- **Archive and restore** — bookmarks are soft-deleted, never destroyed, and can be restored through the API.
- **Retry failed analysis** — bookmarks whose AI step failed (or completed without a summary or embedding) surface a retry action that re-queues the analysis job.
- **Grid and list views** — toggled in the UI; the choice is remembered in `localStorage`.
- **Account management** — change email, change password (which revokes all existing API tokens), and create or delete Sanctum API tokens.
- **REST API** — versioned under `/api/v1`, Sanctum bearer-token auth, described in [`openapi.yaml`](openapi.yaml).
- **SSRF protection** — every URL is validated against a public-address rule before it is fetched, at save time and again at fetch time, including on each redirect hop.

## Project structure

```
app/
  Ai/                   AI building blocks
    Agents/             BookmarkAnalyser, BookmarkAnalysisSynthesizer, BookmarkChat
    BookmarkContentPreparer.php  Normalises and de-duplicates extracted text before analysis
    ParagraphChunker.php         Splits content into overlapping paragraph-aligned chunks
    EmbeddingAggregator.php      Averages per-chunk vectors into one bookmark embedding
  Console/Commands/     user:create, bookmarks:reanalyse, bookmarks:refetch
  Http/
    Controllers/Api/V1/ Bookmark, Collection, and Tag API controllers
    Requests/Api/V1/    Store and update validation
    Resources/          BookmarkResource, CollectionResource
  Jobs/                 ProcessBookmark (fetch + extract), AnalyseBookmark (summarise + embed)
  Livewire/             Home, Chat, Account, Auth\Login, Header\AddBookmark
  Models/               User, Bookmark, Tag, Collection
  Providers/            AppServiceProvider — rate limiter definitions
  Rules/                PublicHttpUrl — the SSRF guard
config/
  ai.php                AI providers, embedding cache, bookmark chunking budgets
  bookmarks.php         Analysis source column, markdown extraction service
database/
  factories/            Model factories used throughout the test suite
  migrations/           Includes the pgvector extension and the 1536-dimension embedding column
resources/
  views/livewire/       Blade views for each Livewire component
  views/layouts/        app and auth layouts
routes/
  api.php               /api/v1 routes, Sanctum-protected
  web.php               Login, home, chat, account
tests/
  Feature/              API, jobs, Livewire, console, and AI agent tests
  Unit/                 EmbeddingAggregator
openapi.yaml            OpenAPI 3.1 description of the v1 API
```

## Getting started

### Prerequisites

- PHP 8.3 or newer (developed against 8.4)
- Composer
- PostgreSQL 17+ with the `vector` (pgvector) extension available
- Node.js and npm
- An OpenAI API key
- A [Flux UI Pro](https://fluxui.dev) licence — `livewire/flux-pro` is pulled from a private Composer repository, so `composer install` needs credentials in `auth.json` for `composer.fluxui.dev`

### Installation

1. Clone the repository and change into it.

2. Create the databases (the test suite uses its own):

   ```bash
   createdb bookmarks
   createdb bookmarks_test
   ```

3. Run the setup script, which installs Composer and npm dependencies, creates `.env`, generates an app key, runs migrations, and builds frontend assets:

   ```bash
   composer setup
   ```

4. Fill in `OPENAI_API_KEY` and your database credentials in `.env`.

5. Create your user account:

   ```bash
   php artisan user:create
   ```

   There is no registration flow — this command is the only way to create an account.

### Environment variables

Application-specific variables. Standard Laravel keys (`APP_*`, `MAIL_*`, `SESSION_*`, `LOG_*`) behave as usual and are omitted here.

| Variable | Required | Default | Description |
|---|---|---|---|
| `DB_CONNECTION` | Yes | `pgsql` | Must be `pgsql`; the app relies on pgvector and `ilike`. |
| `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | Yes | `127.0.0.1`, `5432`, `bookmarks`, `postgres`, empty | PostgreSQL connection details. |
| `QUEUE_CONNECTION` | Yes | `database` | Bookmark processing is queued; `sync` would block every save on a page fetch and paid AI calls. |
| `CACHE_STORE` | No | `database` | Also used as the store for the AI SDK's embedding cache. |
| `OPENAI_API_KEY` | Yes | — | Powers analysis, embeddings, and chat. Without it, saved bookmarks are fetched but never analysed. |
| `BOOKMARKS_ANALYSIS_SOURCE_COLUMN` | No | `markdown_text` | Which extracted column feeds analysis: `markdown_text` or `extracted_text`. Any other value falls back to `markdown_text`. |
| `BOOKMARKS_MARKDOWN_SERVICE_URL` | No | `https://markdown.new/` | Endpoint used to request a Markdown rendering of each page. |
| `BOOKMARKS_MARKDOWN_SERVICE_METHOD` | No | `auto` | Extraction mode sent to that service. |
| `AI_BOOKMARK_ANALYSIS_CHUNK_BUDGET` | No | `6000` | Characters per chunk sent to the analysis agent. |
| `AI_BOOKMARK_EMBEDDING_CHUNK_BUDGET` | No | `4000` | Characters per chunk sent to the embeddings endpoint. |
| `AI_BOOKMARK_CHUNK_OVERLAP` | No | `500` | Characters of overlap between consecutive chunks. |
| `AI_BOOKMARK_MAX_CHUNKS` | No | `12` | Upper bound on chunks per bookmark, capping cost on very long pages. |
| `AI_BOOKMARK_MAX_CANDIDATE_TAGS` | No | `20` | Candidate tags carried from chunk analysis into the synthesis pass. |

`config/ai.php` also defines credentials for Anthropic, Gemini, Cohere, and other providers. None are needed: every agent in this app is pinned to OpenAI.

### Running locally

```bash
composer run dev
```

This runs four processes concurrently: the PHP dev server, a queue worker, `php artisan pail` for live logs, and the Vite dev server. **The queue worker matters** — without one, saved bookmarks stay in `pending` forever.

If you serve the site through Laravel Herd instead (it is available at `https://bookmarks.test`), you still need a worker and an asset build:

```bash
php artisan queue:listen --tries=1 --timeout=0
npm run dev
```

## Usage

### Web UI

| Route | Description |
|---|---|
| `/login` | The only unauthenticated route. |
| `/` | Bookmark list with search, tag and collection filters, grid/list toggle, and the add-bookmark form. |
| `/chat` | Conversational search over your bookmarks. |
| `/account` | Email, password, and API token management. |

Paste a URL into the header form and it appears immediately with `pending` status. The list polls while any bookmark is pending, so the card fills in with its title, image, summary, and tags as the jobs complete.

### Artisan commands

```bash
# Create a user account (interactive)
php artisan user:create

# Re-run AI summarisation, tagging, and embedding for every bookmark
# that has content in either source column
php artisan bookmarks:reanalyse

# Re-fetch page content, which then re-triggers analysis
php artisan bookmarks:refetch
php artisan bookmarks:refetch --limit=50
php artisan bookmarks:refetch --only-missing
```

Both bulk commands prompt for confirmation in production (`--force` skips it), because each queued job is an outbound fetch and a paid AI call that cannot be recalled once dispatched.

### API

All routes live under `/api/v1` and require a Sanctum bearer token, which you create on the `/account` page. Note that changing your password revokes every existing token.

```bash
curl https://bookmarks.test/api/v1/bookmarks \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json"

curl -X POST https://bookmarks.test/api/v1/bookmarks \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"url": "https://laravel.com/docs"}'
```

#### Bookmarks

| Method | Endpoint | Description |
|---|---|---|
| GET | `/api/v1/bookmarks` | Paginated list (15 per page). Query params: `page`, `tag` (slug), `collection` (slug), `q` (combined keyword + semantic search, max 500 chars). |
| POST | `/api/v1/bookmarks` | Create a bookmark from a `url`. Returns `201` for a new bookmark, or `200` with the existing one if the URL was already saved. |
| GET | `/api/v1/bookmarks/{id}` | Show a bookmark with its tags and collections. |
| PUT/PATCH | `/api/v1/bookmarks/{id}` | Update `title`, `description`, `notes`, `tags`, `collection_ids`, or `archived`. Works on archived bookmarks, so `{"archived": false}` restores one. |
| DELETE | `/api/v1/bookmarks/{id}` | Archive (soft-delete) a bookmark. |

#### Collections

| Method | Endpoint | Description |
|---|---|---|
| GET | `/api/v1/collections` | List collections with bookmark counts. |
| POST | `/api/v1/collections` | Create a collection from a `name`. |
| GET | `/api/v1/collections/{id}` | Show a collection with its bookmarks. |
| PUT/PATCH | `/api/v1/collections/{id}` | Rename a collection. |
| DELETE | `/api/v1/collections/{id}` | Delete a collection. Its bookmarks are not deleted. |

#### Tags

| Method | Endpoint | Description |
|---|---|---|
| GET | `/api/v1/tags` | Tags used by your bookmarks, each with a count. |

#### Rate limits

| Limiter | Applies to | Limit |
|---|---|---|
| `api` | All API routes | 60 requests/minute |
| `bookmarks-store` | `POST /api/v1/bookmarks` | 20 requests/minute |

Both are keyed by authenticated user, falling back to IP address for unauthenticated requests.

## Architecture

### Processing pipeline

1. A URL is saved through the UI or the API. It is validated as a public `http`/`https` address and stored with `status = pending`.
2. `ProcessBookmark` fetches the page (re-checking that the URL, and each redirect target, still resolves to a public address), extracts the title, description, OG image, favicon, and readable text, then requests a Markdown rendering from the markdown service. Status becomes `processed`.
3. `AnalyseBookmark` prepares the content, chunks it, generates a summary and tags — synthesising across chunks for long pages — then produces an aggregated 1536-dimension embedding.
4. The bookmark is now semantically searchable and visible to the chat agent.

### Content sources

Each bookmark stores two representations of the page:

- `extracted_text` — Readability-based plain text from the HTML the app fetched itself
- `markdown_text` — Markdown returned by the external extraction service

`BOOKMARKS_ANALYSIS_SOURCE_COLUMN` selects which one feeds summarisation, tagging, and embedding generation. If the preferred column is empty for a given bookmark, the other is used automatically — otherwise a failed markdown fetch would permanently block analysis of a page whose text extracted fine.

Keyword search deliberately ignores this setting and searches both columns, so older bookmarks saved before markdown extraction existed remain findable by their body text.

### Bookmark statuses

| Status | Description |
|---|---|
| `pending` | Saved, waiting for content extraction |
| `processed` | Content extracted and AI analysis complete |
| `failed` | Fetch failed, was blocked as non-public, or returned a 4xx |
| `analysis_failed` | Content extracted but AI analysis failed — retryable from the UI |

### AI agents

- **BookmarkAnalyser** — structured-output agent returning a summary and up to 5 tags for one chunk of content.
- **BookmarkAnalysisSynthesizer** — merges per-chunk summaries and deduplicates candidate tags into one final result for long pages.
- **BookmarkChat** — GPT-4o conversational agent with a `SimilaritySearch` tool scoped to the current user's bookmarks. Conversations are persisted in `agent_conversations` and ownership is verified before one is resumed.

### Models

- **User** — authentication; owns bookmarks, collections, and API tokens
- **Bookmark** — URL, metadata, both extracted content columns, AI summary, notes, embedding, status; soft-deletable
- **Tag** — shared across bookmarks, linked by pivot table
- **Collection** — user-scoped folders, linked to bookmarks by pivot table

## Testing

```bash
php artisan test
```

Or with Composer, which clears cached config first:

```bash
composer test
```

Run a subset while working:

```bash
php artisan test --compact --filter=BookmarkApiTest
```

Tests run against PostgreSQL, not SQLite — pgvector and `ilike` are not portable. `phpunit.xml` points the suite at a `bookmarks_test` database; the connection credentials live in `.env.testing` so they can differ per machine (values set directly in `phpunit.xml` always win, which is why they are not hardcoded there).

The suite covers the API endpoints, both queued jobs, the Livewire components, the console commands, the SSRF validation rule, the AI agents, and embedding aggregation.

## Building

```bash
npm run build
```

Vite compiles `resources/css/app.css` and `resources/js/app.js` into `public/build`. If a frontend change is not showing up, this (or `npm run dev`) is usually why.

## Code style

```bash
vendor/bin/pint
```

Laravel Pint formats all PHP. `vendor/bin/pint --dirty` limits it to files you have changed.

## Continuous integration

[`.github/workflows/ci.yml`](.github/workflows/ci.yml) runs on every push to `main` and on every pull request:

| Job | What it does |
|---|---|
| Lint | Runs `vendor/bin/pint --test`. **Report only** — formatting drift is surfaced in the job summary but never fails the build. |
| Tests | Runs the full suite against a `pgvector/pgvector:pg17` service container, after building frontend assets. |

The test job builds assets before running, because tests that request a full page render layouts containing `@vite`, which throws without a manifest.

Two repository secrets are required, since `livewire/flux-pro` comes from a private Composer repository and `composer install` cannot complete without them:

| Secret | Value |
|---|---|
| `FLUX_USERNAME` | The email address on your Flux Pro licence |
| `FLUX_LICENSE_KEY` | Your Flux Pro licence key |

Add them under **Settings → Secrets and variables → Actions**. No OpenAI key is needed: the suite fakes every agent, embedding, and HTTP call.

## License

MIT — free to use, modify, and distribute, with attribution and no warranty.
