<?php

use App\Livewire\Account;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

test('authenticated user can view account page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/account')
        ->assertOk();
});

test('unauthenticated user is redirected from account page', function () {
    $this->get('/account')->assertRedirect('/login');
});

test('user can update email', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(Account::class)
        ->set('email', 'new@example.com')
        ->call('updateEmail')
        ->assertHasNoErrors();

    expect($user->fresh()->email)->toBe('new@example.com');
});

test('email must be unique', function () {
    User::factory()->create(['email' => 'taken@example.com']);
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(Account::class)
        ->set('email', 'taken@example.com')
        ->call('updateEmail')
        ->assertHasErrors(['email']);
});

test('user can keep their own email without uniqueness error', function () {
    $user = User::factory()->create(['email' => 'mine@example.com']);

    Livewire::actingAs($user)
        ->test(Account::class)
        ->set('email', 'mine@example.com')
        ->call('updateEmail')
        ->assertHasNoErrors();
});

test('user can update password with correct current password', function () {
    $user = User::factory()->create(['password' => 'old-password']);

    Livewire::actingAs($user)
        ->test(Account::class)
        ->set('currentPassword', 'old-password')
        ->set('password', 'new-password-123')
        ->set('passwordConfirmation', 'new-password-123')
        ->call('updatePassword')
        ->assertHasNoErrors();

    expect(Hash::check('new-password-123', $user->fresh()->password))->toBeTrue();
});

test('password update fails with wrong current password', function () {
    $user = User::factory()->create(['password' => 'correct-password']);

    Livewire::actingAs($user)
        ->test(Account::class)
        ->set('currentPassword', 'wrong-password')
        ->set('password', 'new-password-123')
        ->set('passwordConfirmation', 'new-password-123')
        ->call('updatePassword')
        ->assertHasErrors(['currentPassword']);
});

test('password update fails when confirmation does not match', function () {
    $user = User::factory()->create(['password' => 'old-password']);

    Livewire::actingAs($user)
        ->test(Account::class)
        ->set('currentPassword', 'old-password')
        ->set('password', 'new-password-123')
        ->set('passwordConfirmation', 'different-password')
        ->call('updatePassword')
        ->assertHasErrors(['password']);
});

test('user can create an API token', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(Account::class)
        ->set('tokenName', 'My App')
        ->call('createToken')
        ->assertHasNoErrors();

    expect($user->tokens()->where('name', 'My App')->exists())->toBeTrue();
});

test('plaintext token is returned after creation', function () {
    $user = User::factory()->create();

    $component = Livewire::actingAs($user)
        ->test(Account::class)
        ->set('tokenName', 'My App')
        ->call('createToken');

    expect($component->get('newTokenValue'))->not->toBeNull();
});

test('token name is required', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(Account::class)
        ->set('tokenName', '')
        ->call('createToken')
        ->assertHasErrors(['tokenName']);
});

test('user can delete their own API token', function () {
    $user = User::factory()->create();
    $token = $user->createToken('My App');
    $tokenId = $token->accessToken->id;

    Livewire::actingAs($user)
        ->test(Account::class)
        ->call('confirmDeleteToken', $tokenId)
        ->call('deleteToken');

    expect($user->tokens()->find($tokenId))->toBeNull();
});

test('user cannot delete another users token', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $token = $other->createToken('Their App');
    $tokenId = $token->accessToken->id;

    Livewire::actingAs($user)
        ->test(Account::class)
        ->call('confirmDeleteToken', $tokenId)
        ->call('deleteToken');
})->throws(ModelNotFoundException::class);
