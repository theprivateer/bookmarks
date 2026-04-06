<div
    class="flex flex-col"
    style="height: calc(100vh - 4rem)"
    x-data="{
        scrollToBottom() {
            this.$nextTick(() => {
                const el = this.$refs.messages;
                if (el) el.scrollTop = el.scrollHeight;
            });
        }
    }"
    x-effect="scrollToBottom()"
>
    {{-- Header --}}
    <div class="flex items-center justify-between mb-6 shrink-0">
        <div>
            <flux:heading size="xl" level="1">Chat</flux:heading>
            <flux:subheading>Ask questions about your bookmarks</flux:subheading>
        </div>
        @if ($conversationId)
            <flux:button wire:click="newConversation" variant="ghost" icon="plus">
                New conversation
            </flux:button>
        @endif
    </div>

    {{-- Messages area --}}
    <div
        x-ref="messages"
        class="flex-1 overflow-y-auto space-y-4 pr-1"
    >
        @if (empty($messages) && ! $isStreaming)
            {{-- Empty state --}}
            <div class="flex flex-col items-center justify-center h-full text-center py-12">
                <flux:icon name="chat-bubble-left-right" class="size-12 mx-auto text-zinc-300 dark:text-zinc-600 mb-4" />
                <flux:heading size="lg">Ask me about your bookmarks</flux:heading>
                <flux:subheading class="mt-1 max-w-sm">
                    I can search, summarise, and help you find content you've saved.
                </flux:subheading>
                <div class="mt-6 flex flex-wrap gap-2 justify-center">
                    <flux:badge
                        color="zinc"
                        class="cursor-pointer"
                        wire:click="$set('question', 'What have I saved about Laravel?')"
                    >What have I saved about Laravel?</flux:badge>
                    <flux:badge
                        color="zinc"
                        class="cursor-pointer"
                        wire:click="$set('question', 'Show me my machine learning bookmarks')"
                    >Show me my machine learning bookmarks</flux:badge>
                    <flux:badge
                        color="zinc"
                        class="cursor-pointer"
                        wire:click="$set('question', 'Summarise what I know about testing')"
                    >Summarise what I know about testing</flux:badge>
                </div>
            </div>
        @else
            @foreach ($messages as $message)
                @if ($message['role'] === 'user')
                    {{-- User message --}}
                    <div class="flex justify-end">
                        <div class="max-w-[75%] rounded-2xl rounded-tr-sm px-4 py-2.5 bg-blue-600 text-white text-sm">
                            {{ $message['content'] }}
                        </div>
                    </div>
                @else
                    {{-- Assistant message --}}
                    <div class="flex justify-start">
                        <div class="max-w-[75%] rounded-2xl rounded-tl-sm px-4 py-2.5 bg-zinc-100 dark:bg-zinc-700 text-zinc-900 dark:text-zinc-100 text-sm">
                            {!! nl2br(e($message['content'])) !!}
                        </div>
                    </div>
                @endif
            @endforeach

            {{-- Streaming response --}}
            @if ($isStreaming)
                <div class="flex justify-start">
                    <div class="max-w-[75%] rounded-2xl rounded-tl-sm px-4 py-2.5 bg-zinc-100 dark:bg-zinc-700 text-zinc-900 dark:text-zinc-100 text-sm">
                        <div wire:stream="answer">
                            <span class="inline-flex gap-1 text-zinc-400">
                                <span class="animate-bounce" style="animation-delay: 0ms">·</span>
                                <span class="animate-bounce" style="animation-delay: 150ms">·</span>
                                <span class="animate-bounce" style="animation-delay: 300ms">·</span>
                            </span>
                        </div>
                    </div>
                </div>
            @endif
        @endif
    </div>

    {{-- Input area --}}
    <div class="shrink-0 mt-4 pt-4 border-t border-zinc-200 dark:border-zinc-700">
        @error('question')
            <flux:text class="mb-2 text-red-500 text-sm">{{ $message }}</flux:text>
        @enderror
        <form wire:submit="submitPrompt" class="flex gap-2">
            <div class="flex-1">
                <flux:input
                    wire:model="question"
                    type="text"
                    placeholder="Ask about your bookmarks..."
                    icon="chat-bubble-left"
                    :disabled="$isStreaming"
                    autofocus
                />
            </div>
            <flux:button
                type="submit"
                variant="primary"
                :disabled="$isStreaming"
            >
                <span wire:loading.remove wire:target="ask">Send</span>
                <span wire:loading wire:target="ask">Thinking...</span>
            </flux:button>
        </form>
    </div>
</div>
