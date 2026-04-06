# Development Plan: Bookmarks MVP

Phased plan for building the MVP. Each phase is self-contained and results in a working, testable increment. API endpoints and OpenAPI spec are built alongside each phase.

---

## Phase 1: Foundation

Set up the core infrastructure everything else depends on.

### Database
- Switch from SQLite to Postgres (Herd)
- Enable the pgvector extension
- Update `.env` with Postgres credentials

### Auth
- Install Sanctum (`php artisan install:api`)
- Seed a single user account via a seeder
- Disable registration — login page is the only auth route
- Set up Sanctum token auth for API access

### Bookmark Model & Migration
- `bookmarks` table: id, user_id, url, domain, title, description, og_image_url, favicon_url, extracted_text, ai_summary, status (pending/processed/failed), soft deletes, timestamps
- `bookmark_tag` pivot table with a `tags` table: id, name, slug
- Bookmark model with relationships, soft delete trait, and basic scopes (pending, processed, archived)

### API (v1)
- `POST /api/v1/bookmarks` — create a bookmark (accepts URL, returns bookmark with pending status)
- `GET /api/v1/bookmarks` — list bookmarks (paginated)
- `GET /api/v1/bookmarks/{id}` — show single bookmark
- `DELETE /api/v1/bookmarks/{id}` — soft delete
- API Resource classes for consistent JSON structure
- Initial OpenAPI spec (`openapi.yaml`)

### Web UI Shell
- Livewire + Flux UI layout: app shell with navigation
- Login page (Flux UI form components)
- Authenticated redirect to main search page (empty state for now)

### Tests
- Feature tests for auth (login, reject unauthenticated)
- Feature tests for bookmark CRUD API endpoints
- Model factory for Bookmark

---

## Phase 2: Content Extraction & Storage

Fetch page content when a bookmark is saved, extract readable text and metadata.

### Content Extraction Job
- Queued `ProcessBookmark` job dispatched on bookmark creation
- HTTP fetch of the URL
- Extract metadata: title, description, OG image, favicon
- Extract readable text content (evaluate `fivefilters/readability.php` vs in-house)
- Update bookmark record with extracted data
- Handle failures gracefully (retry logic, mark as failed)

### Web UI: Add Bookmark
- Livewire component: input field to paste/type a URL and save
- Instant save with "processing" indicator
- Bookmark appears in list immediately with pending state, updates when processing completes

### Web UI: Browse View (Basic)
- Toggleable card grid / compact list view (Flux UI toggle)
- Cards: OG image (or domain favicon fallback), title, summary snippet, tags
- List: favicon, title, summary, tags inline
- Pagination
- View preference persisted (session or local storage)

### API Updates
- `PATCH /api/v1/bookmarks/{id}` — archive/restore
- Bookmark resource now includes extracted metadata, tags, processing status
- Update OpenAPI spec

### Tests
- Feature tests for ProcessBookmark job (success, failure, retry)
- Feature tests for content extraction (mock HTTP responses)
- Browser tests for add bookmark flow and browse view

---

## Phase 3: AI Processing

AI generates summaries, tags, and embeddings for each bookmark.

### AI Summary & Tagging Job
- Queued `AnalyseBookmark` job (chained after ProcessBookmark)
- Send extracted text to AI via Laravel AI SDK
- Receive: short summary (2-3 sentences), list of tags
- Create/attach tags to bookmark
- Store AI summary on bookmark record

### Vector Embeddings
- Add `embedding` vector column to bookmarks (pgvector)
- Generate embedding from extracted text + title via Laravel AI SDK
- Store embedding on bookmark record as part of AnalyseBookmark job

### Web UI Updates
- Bookmarks now display AI summary and tags
- Tag sidebar: collapsible panel listing all tags with bookmark counts
- Click a tag to filter the browse view
- Sidebar can be hidden/shown (Flux UI sidebar or drawer)

### API Updates
- `GET /api/v1/tags` — list all tags with bookmark counts
- `GET /api/v1/bookmarks?tag={slug}` — filter by tag
- Bookmark resource includes AI summary and tags
- Update OpenAPI spec

### Tests
- Feature tests for AnalyseBookmark job (mock AI responses)
- Feature tests for embedding generation
- Feature tests for tag filtering
- Browser tests for tag sidebar interaction

---

## Phase 4: Semantic Search

The primary interface — natural-language search powered by vector similarity.

### Search Backend
- Search endpoint accepts a text query
- Generate embedding for the query via Laravel AI SDK
- pgvector nearest-neighbour search against bookmark embeddings
- Combine with keyword matching for hybrid results (vector similarity + text relevance)
- Return ranked results

### Web UI: Search-First Landing
- Authenticated landing page is a prominent search bar (Flux UI input)
- Results displayed below as a list with title, summary snippet, tags, relevance indicator
- Empty state with recent bookmarks or suggested queries
- Toggle to switch to browse/dashboard view

### API Updates
- `GET /api/v1/search?q={query}` — semantic search, returns ranked bookmarks
- Update OpenAPI spec

### Tests
- Feature tests for search endpoint (mock embeddings, verify ranking)
- Feature tests for hybrid search (keyword + vector)
- Browser tests for search UI flow

---

## Phase 5: Chat Interface

Conversational search and exploration using the Laravel AI agent.

### Agent Setup
- Configure a bookmarks agent using Laravel AI SDK
- Agent has access to: semantic search (vector), bookmark retrieval, tag listing
- Agent can answer questions like "find that article about X", "what have I saved about Y", "summarise my bookmarks on Z"
- Uses existing `agent_conversations` and `agent_conversation_messages` tables

### Web UI: Chat
- Livewire component: chat interface (message list + input)
- Accessible from the main navigation or a toggle on the search page
- Streamed responses from the AI agent
- Bookmark references in responses are clickable links
- Conversation history preserved per session

### API Updates
- `POST /api/v1/chat` — send a message, receive agent response
- `GET /api/v1/chat/conversations` — list conversations
- `GET /api/v1/chat/conversations/{id}` — conversation history
- Update OpenAPI spec

### Tests
- Feature tests for chat endpoints (mock AI agent responses)
- Feature tests for conversation persistence
- Browser tests for chat UI interaction

---

## Phase 6: Polish & Hardening

Final pass before considering the MVP complete.

### UI Polish
- Responsive design pass (mobile-friendly)
- Loading states and skeleton screens for async content
- Empty states for no bookmarks, no search results, no tags
- Keyboard shortcuts (e.g. `/` to focus search)
- Favicon in browser tab

### Archive & Trash
- Archive action on bookmarks (remove from default views, recoverable)
- Trash with recovery (soft delete with a "trash" view)
- Bulk actions (archive/delete multiple)

### Error Handling
- Failed bookmark processing: retry UI, manual re-trigger
- Graceful degradation when AI service is unavailable
- Rate limiting on API endpoints

### Performance
- Eager loading audit (N+1 prevention)
- Database indexing review
- Cache frequently accessed data (tag counts, recent bookmarks)

### API Finalisation
- Complete OpenAPI spec review
- API versioning headers
- Rate limiting documentation

### Tests
- Full browser test suite covering critical paths
- Architecture tests (Pest arch())
- Test coverage review

---

## Phase Order & Dependencies

```
Phase 1 (Foundation) ─── no dependencies
    │
Phase 2 (Content Extraction) ─── depends on Phase 1
    │
Phase 3 (AI Processing) ─── depends on Phase 2
    │
    ├── Phase 4 (Search) ─── depends on Phase 3 (needs embeddings)
    │
    └── Phase 5 (Chat) ─── depends on Phase 3 (needs embeddings + summaries)
         │
Phase 6 (Polish) ─── depends on all above
```

Phases 4 and 5 can be built in either order or in parallel once Phase 3 is complete.
