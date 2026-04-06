<div
    x-data="{
        view: localStorage.getItem('bookmarks-view') ?? 'grid',
        showTags: localStorage.getItem('bookmarks-show-tags') !== 'false',
        setView(v) { this.view = v; localStorage.setItem('bookmarks-view', v); },
        setShowTags(v) { this.showTags = v; localStorage.setItem('bookmarks-show-tags', v); },
    }"
>
    {{-- Add bookmark form --}}
    <flux:heading size="xl" level="1">Bookmarks</flux:heading>
    <flux:subheading>Your personal bookmark collection</flux:subheading>

    <div class="mt-6 max-w-2xl">
        <form wire:submit="addBookmark" class="flex gap-2">
            <div class="flex-1">
                <flux:input
                    wire:model="newUrl"
                    type="url"
                    placeholder="https://example.com"
                    icon="link"
                />
            </div>
            <flux:button type="submit" variant="primary" icon="plus">
                Add
            </flux:button>
        </form>
        @error('newUrl')
            <flux:text class="mt-1 text-red-500 text-sm">{{ $message }}</flux:text>
        @enderror
    </div>

    @if ($bookmarks->isNotEmpty() || $tagFilter !== '')
        {{-- Toolbar --}}
        <div class="mt-8 flex items-center justify-between mb-4">
            <div class="flex items-center gap-2">
                <flux:text class="text-zinc-500 dark:text-zinc-400">
                    {{ $bookmarks->total() }} {{ Str::plural('bookmark', $bookmarks->total()) }}
                </flux:text>
                @if ($tagFilter !== '')
                    <flux:badge
                        color="blue"
                        icon-trailing="x-mark"
                        wire:click="clearTagFilter"
                        class="cursor-pointer"
                    >{{ $tagFilter }}</flux:badge>
                @endif
            </div>

            <div class="flex gap-1">
                @if ($tags->isNotEmpty())
                    <flux:button
                        size="sm"
                        variant="ghost"
                        icon="tag"
                        x-on:click="setShowTags(!showTags)"
                        title="Toggle tag filter"
                    />
                @endif
                <flux:button
                    size="sm"
                    variant="ghost"
                    icon="squares-2x2"
                    x-bind:class="{ 'bg-zinc-100 dark:bg-zinc-700': view === 'grid' }"
                    x-on:click="setView('grid')"
                    title="Grid view"
                />
                <flux:button
                    size="sm"
                    variant="ghost"
                    icon="bars-3"
                    x-bind:class="{ 'bg-zinc-100 dark:bg-zinc-700': view === 'list' }"
                    x-on:click="setView('list')"
                    title="List view"
                />
            </div>
        </div>

        {{-- Content area: tag sidebar + bookmarks --}}
        <div class="flex gap-6">

            {{-- Tag sidebar --}}
            @if ($tags->isNotEmpty())
                <div
                    x-show="showTags"
                    x-cloak
                    class="w-48 shrink-0"
                >
                    <flux:navlist>
                        @if ($tagFilter !== '')
                            <flux:navlist.item
                                wire:click="clearTagFilter"
                                class="cursor-pointer text-zinc-400 dark:text-zinc-500 text-xs mb-1"
                            >
                                All bookmarks
                            </flux:navlist.item>
                        @endif

                        @foreach ($tags as $tag)
                            <flux:navlist.item
                                wire:click="filterByTag('{{ $tag->slug }}')"
                                :current="$tagFilter === '{{ $tag->slug }}'"
                                badge="{{ $tag->bookmarks_count }}"
                                class="cursor-pointer"
                            >
                                {{ $tag->name }}
                            </flux:navlist.item>
                        @endforeach
                    </flux:navlist>
                </div>
            @endif

            {{-- Bookmark grid/list with polling --}}
            <div class="flex-1 min-w-0" @if($hasPendingBookmarks) wire:poll.3s @endif>

                @if ($bookmarks->isNotEmpty())
                    {{-- Grid view --}}
                    <div x-show="view === 'grid'" x-cloak>
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                            @foreach ($bookmarks as $bookmark)
                                <flux:card class="p-0 flex flex-col overflow-hidden">
                                    {{-- OG image or placeholder --}}
                                    @if ($bookmark->og_image_url)
                                        <img
                                            src="{{ $bookmark->og_image_url }}"
                                            alt=""
                                            class="w-full h-36 object-cover"
                                        >
                                    @else
                                        <div class="w-full h-36 bg-zinc-100 dark:bg-zinc-700 flex items-center justify-center">
                                            <span class="text-3xl font-bold text-zinc-400 dark:text-zinc-500 uppercase">
                                                {{ mb_substr($bookmark->domain ?? $bookmark->url, 0, 1) }}
                                            </span>
                                        </div>
                                    @endif

                                    <div class="p-4 flex flex-col flex-1">
                                        {{-- Status badges --}}
                                        @if ($bookmark->status === 'pending')
                                            <flux:badge color="amber" class="self-start mb-2">Processing...</flux:badge>
                                        @elseif ($bookmark->status === 'failed')
                                            <flux:badge color="red" class="self-start mb-2">Failed</flux:badge>
                                        @endif

                                        <flux:heading level="3" class="line-clamp-2 mb-1">
                                            <a href="{{ $bookmark->url }}" target="_blank" rel="noopener" class="hover:underline">
                                                {{ $bookmark->title ?? $bookmark->url }}
                                            </a>
                                        </flux:heading>

                                        <flux:badge color="zinc" size="sm" class="self-start mb-2">{{ $bookmark->domain }}</flux:badge>

                                        {{-- Tags --}}
                                        @if ($bookmark->tags->isNotEmpty())
                                            <div class="flex flex-wrap gap-1 mb-2">
                                                @foreach ($bookmark->tags as $tag)
                                                    <flux:badge
                                                        color="blue"
                                                        size="sm"
                                                        wire:click="filterByTag('{{ $tag->slug }}')"
                                                        class="cursor-pointer"
                                                    >{{ $tag->name }}</flux:badge>
                                                @endforeach
                                            </div>
                                        @endif

                                        {{-- Summary or description --}}
                                        @php $snippet = $bookmark->ai_summary ?? $bookmark->description; @endphp
                                        @if ($snippet)
                                            <flux:text class="text-sm text-zinc-500 dark:text-zinc-400 line-clamp-3 flex-1">
                                                {{ $snippet }}
                                            </flux:text>
                                        @endif

                                        <div class="mt-3 flex justify-end">
                                            <flux:button
                                                size="sm"
                                                variant="ghost"
                                                icon="trash"
                                                wire:click="deleteBookmark({{ $bookmark->id }})"
                                                wire:confirm="Delete this bookmark?"
                                                class="text-zinc-400 hover:text-red-500"
                                            />
                                        </div>
                                    </div>
                                </flux:card>
                            @endforeach
                        </div>
                    </div>

                    {{-- List view --}}
                    <div x-show="view === 'list'" x-cloak>
                        <div class="flex flex-col divide-y divide-zinc-200 dark:divide-zinc-700">
                            @foreach ($bookmarks as $bookmark)
                                <div class="flex items-center gap-3 py-3">
                                    {{-- Favicon --}}
                                    @if ($bookmark->favicon_url)
                                        <img src="{{ $bookmark->favicon_url }}" alt="" class="size-4 shrink-0">
                                    @else
                                        <div class="size-4 shrink-0 rounded-sm bg-zinc-200 dark:bg-zinc-600 flex items-center justify-center">
                                            <span class="text-[8px] font-bold text-zinc-500 uppercase">
                                                {{ mb_substr($bookmark->domain ?? $bookmark->url, 0, 1) }}
                                            </span>
                                        </div>
                                    @endif

                                    {{-- Title + meta --}}
                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-center gap-2 truncate">
                                            <a
                                                href="{{ $bookmark->url }}"
                                                target="_blank"
                                                rel="noopener"
                                                class="font-medium text-sm truncate hover:underline"
                                            >
                                                {{ $bookmark->title ?? $bookmark->url }}
                                            </a>
                                            @if ($bookmark->status === 'pending')
                                                <flux:badge color="amber" size="sm">Processing...</flux:badge>
                                            @elseif ($bookmark->status === 'failed')
                                                <flux:badge color="red" size="sm">Failed</flux:badge>
                                            @endif
                                        </div>
                                        <div class="flex items-center gap-2 mt-0.5 flex-wrap">
                                            <span class="text-xs text-zinc-400 dark:text-zinc-500 shrink-0">{{ $bookmark->domain }}</span>
                                            @foreach ($bookmark->tags as $tag)
                                                <flux:badge
                                                    color="blue"
                                                    size="sm"
                                                    wire:click="filterByTag('{{ $tag->slug }}')"
                                                    class="cursor-pointer"
                                                >{{ $tag->name }}</flux:badge>
                                            @endforeach
                                            @php $snippet = $bookmark->ai_summary ?? $bookmark->description; @endphp
                                            @if ($snippet)
                                                <span class="text-xs text-zinc-400 dark:text-zinc-500 truncate">— {{ $snippet }}</span>
                                            @endif
                                        </div>
                                    </div>

                                    {{-- Delete --}}
                                    <flux:button
                                        size="sm"
                                        variant="ghost"
                                        icon="trash"
                                        wire:click="deleteBookmark({{ $bookmark->id }})"
                                        wire:confirm="Delete this bookmark?"
                                        class="shrink-0 text-zinc-400 hover:text-red-500"
                                    />
                                </div>
                            @endforeach
                        </div>
                    </div>

                    {{-- Pagination --}}
                    <div class="mt-6">
                        {{ $bookmarks->links() }}
                    </div>

                @else
                    {{-- Filtered empty state --}}
                    <div class="text-center py-12">
                        <flux:icon name="tag" class="size-10 mx-auto text-zinc-300 dark:text-zinc-600 mb-3" />
                        <flux:heading size="lg">No bookmarks with this tag</flux:heading>
                        <flux:subheading class="mt-1">
                            <flux:button variant="ghost" wire:click="clearTagFilter">Clear filter</flux:button>
                        </flux:subheading>
                    </div>
                @endif

            </div>{{-- end bookmark grid/list --}}
        </div>{{-- end flex layout --}}

    @else
        {{-- Empty state --}}
        <div class="mt-16 text-center">
            <flux:icon name="bookmark" class="size-12 mx-auto text-zinc-300 dark:text-zinc-600 mb-4" />
            <flux:heading size="lg">No bookmarks yet</flux:heading>
            <flux:subheading class="mt-1">Paste a URL above to save your first bookmark.</flux:subheading>
        </div>
    @endif
</div>
