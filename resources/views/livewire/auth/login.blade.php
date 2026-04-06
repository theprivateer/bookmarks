<flux:card class="w-full max-w-sm">
    <form wire:submit="authenticate" class="space-y-6">
        <div>
            <flux:heading size="lg">Sign in</flux:heading>
            <flux:subheading>Welcome back to Bookmarks</flux:subheading>
        </div>

        <flux:input wire:model="email" label="Email" type="email" placeholder="you@example.com" />
        <flux:input wire:model="password" label="Password" type="password" />
        <flux:checkbox wire:model="remember" label="Remember me" />

        <flux:button type="submit" variant="primary" class="w-full">Sign in</flux:button>
    </form>
</flux:card>
