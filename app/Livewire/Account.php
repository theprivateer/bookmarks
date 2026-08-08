<?php

namespace App\Livewire;

use Flux\Flux;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Account extends Component
{
    public string $email = '';

    public string $currentPassword = '';

    public string $password = '';

    public string $passwordConfirmation = '';

    public string $tokenName = '';

    public ?string $newTokenValue = null;

    public ?int $tokenToDelete = null;

    public function mount(): void
    {
        $this->email = auth()->user()->email;
    }

    public function updateEmail(): void
    {
        $this->validate([
            'email' => ['required', 'email', Rule::unique('users', 'email')->ignore(auth()->id())],
        ]);

        auth()->user()->update(['email' => $this->email]);

        Flux::toast(text: 'Email address updated.', variant: 'success');
    }

    public function updatePassword(): void
    {
        $this->validate([
            'currentPassword' => ['required', 'current_password'],
            'password' => ['required', 'min:8', 'same:passwordConfirmation'],
        ]);

        $user = auth()->user();

        $user->update(['password' => $this->password]);

        // A password change is the one moment a user expects previously issued
        // credentials to stop working, so existing API tokens are revoked and the
        // session id is rotated rather than left valid under the old password.
        $revokedTokens = $user->tokens()->count();
        $user->tokens()->delete();

        session()->regenerate();

        $this->reset(['currentPassword', 'password', 'passwordConfirmation']);

        Flux::toast(
            text: $revokedTokens > 0
                ? "Password updated. {$revokedTokens} API token(s) were revoked."
                : 'Password updated.',
            variant: 'success',
        );
    }

    public function createToken(): void
    {
        $this->validate([
            'tokenName' => ['required', 'string', 'max:255'],
        ]);

        $result = auth()->user()->createToken($this->tokenName);
        $this->newTokenValue = $result->plainTextToken;

        $this->reset('tokenName');
        $this->modal('token-created')->show();
    }

    public function dismissToken(): void
    {
        $this->newTokenValue = null;
        $this->modal('token-created')->close();
    }

    public function confirmDeleteToken(int $tokenId): void
    {
        $this->tokenToDelete = $tokenId;
        $this->modal('delete-token')->show();
    }

    public function deleteToken(): void
    {
        if ($this->tokenToDelete) {
            auth()->user()->tokens()->findOrFail($this->tokenToDelete)->delete();
            $this->tokenToDelete = null;
        }

        $this->modal('delete-token')->close();

        Flux::toast(text: 'API token deleted.', variant: 'success');
    }

    public function render()
    {
        return view('livewire.account', [
            'tokens' => auth()->user()->tokens()->latest()->get(),
        ]);
    }
}
