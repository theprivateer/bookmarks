<div class="max-w-2xl mx-auto py-8 px-4">
    <flux:heading size="xl" level="1">Account</flux:heading>
    <flux:text class="mt-1 mb-8">Manage your profile, password, and API access.</flux:text>

    <flux:tab.group>
    <flux:tabs class="mb-6">
        <flux:tab name="profile">Profile</flux:tab>
        <flux:tab name="password">Password</flux:tab>
        <flux:tab name="api-keys">API Keys</flux:tab>
    </flux:tabs>

        {{-- Profile Tab --}}
        <flux:tab.panel name="profile">
            <flux:card class="space-y-6">
                <div>
                    <flux:heading size="lg">Email address</flux:heading>
                    <flux:text class="mt-1">Update the email address associated with your account.</flux:text>
                </div>

                <form wire:submit="updateEmail" class="space-y-4">
                    <flux:field>
                        <flux:label>Email</flux:label>
                        <flux:input type="email" wire:model="email" placeholder="you@example.com" />
                        <flux:error name="email" />
                    </flux:field>

                    <div class="flex justify-end">
                        <flux:button type="submit" variant="primary">Save email</flux:button>
                    </div>
                </form>
            </flux:card>
        </flux:tab.panel>

        {{-- Password Tab --}}
        <flux:tab.panel name="password">
            <flux:card class="space-y-6">
                <div>
                    <flux:heading size="lg">Change password</flux:heading>
                    <flux:text class="mt-1">Ensure your account uses a strong password.</flux:text>
                </div>

                <form wire:submit="updatePassword" class="space-y-4">
                    <flux:field>
                        <flux:label>Current password</flux:label>
                        <flux:input type="password" wire:model="currentPassword" />
                        <flux:error name="currentPassword" />
                    </flux:field>

                    <flux:field>
                        <flux:label>New password</flux:label>
                        <flux:input type="password" wire:model="password" />
                        <flux:error name="password" />
                    </flux:field>

                    <flux:field>
                        <flux:label>Confirm new password</flux:label>
                        <flux:input type="password" wire:model="passwordConfirmation" />
                    </flux:field>

                    <div class="flex justify-end">
                        <flux:button type="submit" variant="primary">Update password</flux:button>
                    </div>
                </form>
            </flux:card>
        </flux:tab.panel>

        {{-- API Keys Tab --}}
        <flux:tab.panel name="api-keys">
            <div class="space-y-6">
                <flux:card class="space-y-6">
                    <div>
                        <flux:heading size="lg">Create API token</flux:heading>
                        <flux:text class="mt-1">Tokens allow programmatic access to the API.</flux:text>
                    </div>

                    <form wire:submit="createToken" class="space-y-4">
                        <flux:field>
                            <flux:label>Token name</flux:label>
                            <flux:input wire:model="tokenName" placeholder="e.g. My App" />
                            <flux:error name="tokenName" />
                        </flux:field>

                        <div class="flex justify-end">
                            <flux:button type="submit" variant="primary">Create token</flux:button>
                        </div>
                    </form>
                </flux:card>

                @if ($tokens->isNotEmpty())
                    <flux:card class="space-y-4">
                        <flux:heading size="lg">Active tokens</flux:heading>

                        <flux:table>
                            <flux:table.columns>
                                <flux:table.column>Name</flux:table.column>
                                <flux:table.column>Created</flux:table.column>
                                <flux:table.column>Last used</flux:table.column>
                                <flux:table.column></flux:table.column>
                            </flux:table.columns>
                            <flux:table.rows>
                                @foreach ($tokens as $token)
                                    <flux:table.row :key="$token->id">
                                        <flux:table.cell variant="strong">{{ $token->name }}</flux:table.cell>
                                        <flux:table.cell>{{ $token->created_at->diffForHumans() }}</flux:table.cell>
                                        <flux:table.cell>
                                            {{ $token->last_used_at ? $token->last_used_at->diffForHumans() : 'Never' }}
                                        </flux:table.cell>
                                        <flux:table.cell align="end">
                                            <flux:button
                                                wire:click="confirmDeleteToken({{ $token->id }})"
                                                variant="danger"
                                                size="sm"
                                            >Delete</flux:button>
                                        </flux:table.cell>
                                    </flux:table.row>
                                @endforeach
                            </flux:table.rows>
                        </flux:table>
                    </flux:card>
                @endif
            </div>
        </flux:tab.panel>
    </flux:tab.group>

    {{-- Token created modal --}}
    <flux:modal name="token-created" class="md:w-[32rem]">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Token created</flux:heading>
                <flux:text class="mt-2">Copy your token now — it won't be shown again.</flux:text>
            </div>

            @if ($newTokenValue)
                <flux:input
                    value="{{ $newTokenValue }}"
                    readonly
                    copyable
                    label="Your API token"
                />
            @endif

            <div class="flex justify-end">
                <flux:button wire:click="dismissToken" variant="primary">Done</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Delete token confirmation modal --}}
    <flux:modal name="delete-token" class="min-w-[22rem]">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Delete token?</flux:heading>
                <flux:text class="mt-2">
                    Any applications using this token will lose access immediately.
                    This action cannot be undone.
                </flux:text>
            </div>

            <div class="flex gap-2 justify-end">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancel</flux:button>
                </flux:modal.close>
                <flux:button wire:click="deleteToken" variant="danger">Delete token</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
