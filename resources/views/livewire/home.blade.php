<div>
    <flux:heading size="xl" level="1">Bookmarks</flux:heading>
    <flux:subheading>Your personal bookmark collection</flux:subheading>

    {{-- Add bookmark form --}}
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

    @if ($bookmarks->isNotEmpty())
        {{-- Toolbar: count + view toggle --}}
        <div
            x-data="{
                view: localStorage.getItem('bookmarks-view') ?? 'grid',
                setView(v) { this.view = v; localStorage.setItem('bookmarks-view', v); }
            }"
            class="mt-8"
        >
            <div class="flex items-center justify-between mb-4">
                <flux:text class="text-zinc-500 dark:text-zinc-400">
                    {{ $bookmarks->total() }} {{ Str::plural('bookmark', $bookmarks->total()) }}
                </flux:text>

                <div class="flex gap-1">
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

            {{-- Polling wrapper when pending bookmarks exist --}}
            <div @if($hasPendingBookmarks) wire:poll.3s @endif>

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

                                    @if ($bookmark->description)
                                        <flux:text class="text-sm text-zinc-500 dark:text-zinc-400 line-clamp-3 flex-1">
                                            {{ $bookmark->description }}
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

                                {{-- Title + domain --}}
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
                                    <div class="flex items-center gap-2 mt-0.5">
                                        <span class="text-xs text-zinc-400 dark:text-zinc-500">{{ $bookmark->domain }}</span>
                                        @if ($bookmark->description)
                                            <span class="text-xs text-zinc-400 dark:text-zinc-500 truncate">— {{ $bookmark->description }}</span>
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

            </div>{{-- end polling wrapper --}}

            {{-- Pagination --}}
            <div class="mt-6">
                {{ $bookmarks->links() }}
            </div>
        </div>

    @else
        {{-- Empty state --}}
        <div class="mt-16 text-center">
            <flux:icon name="bookmark" class="size-12 mx-auto text-zinc-300 dark:text-zinc-600 mb-4" />
            <flux:heading size="lg">No bookmarks yet</flux:heading>
            <flux:subheading class="mt-1">Paste a URL above to save your first bookmark.</flux:subheading>
        </div>
    @endif
</div>
