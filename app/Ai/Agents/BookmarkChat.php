<?php

namespace App\Ai\Agents;

use App\Models\Bookmark;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Attributes\UseSmartestModel;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Laravel\Ai\Tools\SimilaritySearch;
use Stringable;

#[Provider(Lab::OpenAI)]
#[UseSmartestModel]
#[MaxTokens(2048)]
#[Temperature(0.7)]
#[MaxSteps(5)]
class BookmarkChat implements Agent, Conversational, HasTools
{
    use Promptable, RemembersConversations;

    public function instructions(): Stringable|string
    {
        return 'You are a bookmark assistant. You help users find and learn about their saved bookmarks. '
            .'Use the search tool to find relevant bookmarks when the user asks about topics. '
            .'When referencing bookmarks, include their titles and URLs. Be concise and helpful.';
    }

    public function tools(): iterable
    {
        return [
            SimilaritySearch::usingModel(
                Bookmark::class,
                'embedding',
                minSimilarity: 0.3,
                limit: 10,
                query: fn ($q) => $q->where('user_id', $this->conversationParticipant()->id),
            )->withDescription('Search the user\'s saved bookmarks by semantic similarity.'),
        ];
    }
}
