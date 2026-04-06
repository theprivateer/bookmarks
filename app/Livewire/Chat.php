<?php

namespace App\Livewire;

use App\Ai\Agents\BookmarkChat;
use Illuminate\View\View;
use Laravel\Ai\Streaming\Events\TextDelta;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

#[Layout('layouts.app')]
class Chat extends Component
{
    #[Validate('required|string|max:1000')]
    public string $question = '';

    public string $answer = '';

    public ?string $conversationId = null;

    public array $messages = [];

    public bool $isStreaming = false;

    public function submitPrompt(): void
    {
        $this->validate();

        $this->messages[] = ['role' => 'user', 'content' => $this->question];
        $this->answer = '';
        $this->isStreaming = true;

        $this->js('$wire.ask()');
    }

    public function ask(): void
    {
        $agent = new BookmarkChat;
        $user = auth()->user();

        if ($this->conversationId) {
            $agent->continue($this->conversationId, as: $user);
        } else {
            $agent->forUser($user);
        }

        $stream = $agent->stream($this->question);

        foreach ($stream as $event) {
            if ($event instanceof TextDelta) {
                $this->stream(content: $event->delta, to: 'answer');
            }
        }

        $this->conversationId ??= $agent->currentConversation();

        $this->messages[] = ['role' => 'assistant', 'content' => $stream->text];
        $this->answer = $stream->text;
        $this->isStreaming = false;
        $this->question = '';
    }

    public function newConversation(): void
    {
        $this->conversationId = null;
        $this->messages = [];
        $this->answer = '';
        $this->isStreaming = false;
        $this->question = '';
    }

    public function render(): View
    {
        return view('livewire.chat');
    }
}
