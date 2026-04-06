<?php

use App\Livewire\Auth\Login;
use App\Models\User;
use Livewire\Livewire;

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

test('user can log out', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/logout')
        ->assertRedirect(route('login'));

    $this->assertGuest();
});
