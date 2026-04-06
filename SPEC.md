# Product Spec: Bookmarks

## Problem Statement
Browser bookmarks are a graveyard — hard to search, impossible to organise at scale, and you never find that article you saved three months ago. This app uses AI to automatically understand, categorise, and surface saved links so you never lose track of what you've read.

## Target User
Single authenticated user — personal tool, secured behind auth, with an API designed for future mobile app and browser extension clients.

## Core Features

1. **Save a URL** — via web UI (paste/type), API (mobile app, browser extension), or future import. Saving is instant; AI processing happens in the background via queued jobs.

2. **AI Content Processing** (queued per bookmark):
   - Fetch and extract full readable text (in-house or via `fivefilters/readability.php` — evaluate reliability)
   - Store metadata: title, description, favicon, OG image
   - Generate a short summary of the content via Laravel AI SDK
   - Auto-assign categories and tags — AI is the sole organiser (no manual folders/collections)
   - Generate vector embeddings via Laravel AI SDK for semantic search, stored in pgvector

3. **Smart Search Bar** — the primary UI. Natural-language queries powered by vector/embedding search (Laravel AI SDK + pgvector). Semantic understanding, not just keyword matching.

4. **Chat Interface** — for deeper exploration. Conversational search using the Laravel AI agent setup (agent_conversations tables). Ask follow-up questions, get contextual bookmark recommendations.

5. **Dashboard / Browse View** — secondary view. Visual card/grid layout showing bookmarks with OG images, AI summaries, and tags. Switchable from the search-first landing page.

6. **Soft Delete / Archive** — bookmarks can be archived or trashed with recovery. Never auto-deleted.

7. **API (Sanctum token auth)** — RESTful, versioned (`/api/v1/`), API-first so all features work headlessly for future mobile app and browser extension.

## Anti-Features
- No multi-user / signup — single authenticated user only
- No manual organisation (folders, collections) — AI handles all categorisation
- No synchronous AI processing — always queued
- No browser extension or mobile app in this repo — separate projects consuming the API
- No auto-deletion or expiry of bookmarks
- No page screenshots — OG image and favicon only
- No import from other services in MVP

## Technical Decisions
- **Web UI**: Livewire v4 + Flux UI Pro (fluxui.dev) — reactive components without writing JavaScript. All UI built with `<flux:*>` components.
- **Text extraction**: In-house HTTP + DOM parsing, with `fivefilters/readability.php` as a candidate if reliability is solid
- **Search**: Vector/embedding search from day one via Laravel AI SDK + pgvector
- **Embedding storage**: pgvector (keep everything in Postgres)
- **AI provider**: Abstracted through Laravel AI SDK (Prism) — provider-agnostic
- **Screenshots**: Shelved. OG image + favicon is sufficient

## Open Questions
- **Readability package**: Evaluate `fivefilters/readability.php` vs rolling a simpler in-house extractor — worth a spike before committing
