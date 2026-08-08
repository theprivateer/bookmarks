<?php

namespace App\Livewire\Header;

use App\Jobs\ProcessBookmark;
use App\Rules\PublicHttpUrl;
use Flux\Flux;
use Illuminate\View\View;
use Livewire\Component;

class AddBookmark extends Component
{
    public string $newUrl = '';

    /**
     * Declared here rather than via #[Validate] because the rule is an object.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function rules(): array
    {
        return [
            'newUrl' => ['required', 'url:http,https', 'max:2048', new PublicHttpUrl],
        ];
    }

    public function addBookmark(): void
    {
        $this->validate();

        // Saving a URL twice would pay for a second page fetch, markdown call and
        // AI analysis to produce a duplicate row, so an existing bookmark is
        // surfaced instead. Archived ones are restored rather than re-fetched.
        $existing = auth()->user()->bookmarks()
            ->withTrashed()
            ->where('url', $this->newUrl)
            ->first();

        if ($existing !== null) {
            $wasArchived = $existing->trashed();

            if ($wasArchived) {
                $existing->restore();
            }

            $this->reset('newUrl');

            Flux::toast(
                heading: $wasArchived ? 'Bookmark restored' : 'Already saved',
                text: $wasArchived
                    ? 'That link was archived, so we brought it back.'
                    : 'That link is already in your bookmarks.',
                variant: 'success',
            );

            return;
        }

        $bookmark = auth()->user()->bookmarks()->create([
            'url' => $this->newUrl,
            'domain' => parse_url($this->newUrl, PHP_URL_HOST),
            'status' => 'pending',
        ]);

        ProcessBookmark::dispatch($bookmark->id);

        $this->reset('newUrl');

        Flux::toast(
            heading: 'Bookmark added',
            text: 'We saved the link and started processing it.',
            variant: 'success',
        );
    }

    public function render(): View
    {
        return view('livewire.header.add-bookmark');
    }
}
