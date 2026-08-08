<?php

namespace App\Livewire;

use App\Ai\Agents\BookmarkChat;
use Illuminate\Support\Facades\DB;
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

    /** @var list<array{role: string, content: string}> */
    public array $messages = [];

    public bool $isStreaming = false;

    public function submitPrompt(): void
    {
        $this->validate();

        $this->messages[] = ['role' => 'user', 'content' => $this->question];
        $this->answer = '';
        $this->isStreaming = true;

        // Defer ask() to the browser's event loop so Livewire can flush the updated
        // properties (messages, isStreaming) to the DOM before the long-running stream
        // blocks the response.
        $this->js('$wire.ask()');
    }

    public function ask(): void
    {
        $agent = new BookmarkChat;
        $user = auth()->user();

        if ($this->conversationId) {
            // conversationId is a public property, so it is settable from the client.
            // Neither RemembersConversations::continue() nor the conversation store
            // checks ownership, so without this a tampered id would load and reveal
            // another user's history.
            abort_unless($this->ownsConversation($this->conversationId), 403);

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

    /**
     * The package stores conversations in a plain table with no model, so this
     * checks ownership directly.
     */
    private function ownsConversation(string $conversationId): bool
    {
        return DB::table('agent_conversations')
            ->where('id', $conversationId)
            ->where('user_id', auth()->id())
            ->exists();
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
