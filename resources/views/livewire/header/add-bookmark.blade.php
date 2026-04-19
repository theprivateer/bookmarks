<div class="w-full">
    <form wire:submit="addBookmark" class="flex w-full flex-col gap-2 md:flex-row">
        <div class="min-w-0 flex-1">
            <flux:input
                wire:model="newUrl"
                type="url"
                placeholder="https://example.com"
                icon="link"
                wire:loading.attr="disabled"
                wire:target="addBookmark"
            />
        </div>

        <flux:button
            type="submit"
            variant="primary"
            icon="plus"
            wire:loading.attr="disabled"
            wire:target="addBookmark"
            class="md:self-start"
        >
            <span wire:loading.remove wire:target="addBookmark">Add bookmark</span>
            <span wire:loading wire:target="addBookmark">Adding...</span>
        </flux:button>
    </form>

    @error('newUrl')
        <flux:text class="mt-1 text-sm text-red-500">{{ $message }}</flux:text>
    @enderror
</div>
