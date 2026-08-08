<?php

use App\Livewire\Auth\Login;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

beforeEach(fn () => RateLimiter::clear(strtolower('throttled@example.com').'|127.0.0.1'));

test('guest can see login page', function () {
    $this->get('/login')->assertOk();
});

test('user can log in with valid credentials', function () {
    $user = User::factory()->create();

    Livewire::test(Login::class)
        ->set('email', $user->email)
        ->set('password', 'password')
        ->call('authenticate')
        ->assertRedirect(route('home'));

    $this->assertAuthenticatedAs($user);
});

test('user cannot log in with invalid credentials', function () {
    $user = User::factory()->create();

    Livewire::test(Login::class)
        ->set('email', $user->email)
        ->set('password', 'wrong-password')
        ->call('authenticate')
        ->assertHasErrors('email');

    $this->assertGuest();
});

test('authenticated user is redirected from login to home', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/login')
        ->assertRedirect(route('home'));
});

test('unauthenticated user is redirected from home to login', function () {
    $this->get('/')
        ->assertRedirect('/login');
});

test('login is rate limited after five failed attempts', function () {
    $user = User::factory()->create(['email' => 'throttled@example.com']);

    $component = Livewire::test(Login::class)
        ->set('email', $user->email)
        ->set('password', 'wrong-password');

    foreach (range(1, 5) as $ignored) {
        $component->call('authenticate')->assertHasErrors('email');
    }

    // The sixth attempt is refused by the limiter rather than reaching Auth::attempt,
    // so even the correct password must not authenticate.
    $component->set('password', 'password')
        ->call('authenticate')
        ->assertHasErrors('email');

    $this->assertGuest();
});

test('rate limiter is cleared after a successful login', function () {
    $user = User::factory()->create(['email' => 'throttled@example.com']);

    $component = Livewire::test(Login::class)
        ->set('email', $user->email)
        ->set('password', 'wrong-password');

    foreach (range(1, 4) as $ignored) {
        $component->call('authenticate')->assertHasErrors('email');
    }

    $component->set('password', 'password')
        ->call('authenticate')
        ->assertRedirect(route('home'));

    expect(RateLimiter::attempts('throttled@example.com|127.0.0.1'))->toBe(0);
});

test('user can log out', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/logout')
        ->assertRedirect(route('login'));

    $this->assertGuest();
});
