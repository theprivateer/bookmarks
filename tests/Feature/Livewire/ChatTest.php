<?php

use App\Ai\Agents\BookmarkChat;
use App\Livewire\Chat;
use App\Livewire\Header\AddBookmark;
use App\Models\User;
use Livewire\Livewire;

test('chat page renders for authenticated users', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/chat')
        ->assertOk()
        ->assertSeeLivewire(Chat::class)
        ->assertSeeLivewire(AddBookmark::class);
});

test('unauthenticated users are redirected to login', function () {
    $this->get('/chat')
        ->assertRedirect('/login');
});

test('can submit a prompt and message is added', function () {
    BookmarkChat::fake(['Here are your Laravel bookmarks...']);

    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(Chat::class)
        ->set('question', 'What bookmarks do I have about Laravel?')
        ->call('submitPrompt')
        ->assertSet('isStreaming', true)
        ->call('ask')
        ->assertSet('isStreaming', false);
});

test('agent is prompted with the user question', function () {
    BookmarkChat::fake(['Here are your bookmarks...']);

    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(Chat::class)
        ->set('question', 'What bookmarks do I have about Laravel?')
        ->call('submitPrompt')
        ->call('ask');

    BookmarkChat::assertPrompted('What bookmarks do I have about Laravel?');
});

test('new conversation resets state', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(Chat::class)
        ->set('conversationId', 'some-uuid')
        ->set('messages', [['role' => 'user', 'content' => 'Hello']])
        ->set('answer', 'Previous answer')
        ->call('newConversation')
        ->assertSet('conversationId', null)
        ->assertSet('messages', [])
        ->assertSet('answer', '')
        ->assertSet('isStreaming', false);
});

test('empty question is rejected', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(Chat::class)
        ->set('question', '')
        ->call('submitPrompt')
        ->assertHasErrors(['question' => 'required']);
});

test('question exceeding max length is rejected', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(Chat::class)
        ->set('question', str_repeat('a', 1001))
        ->call('submitPrompt')
        ->assertHasErrors(['question' => 'max']);
});
