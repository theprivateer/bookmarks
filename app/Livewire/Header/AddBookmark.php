<?php

namespace App\Livewire\Header;

use App\Jobs\ProcessBookmark;
use Flux\Flux;
use Illuminate\View\View;
use Livewire\Attributes\Validate;
use Livewire\Component;

class AddBookmark extends Component
{
    #[Validate('required|url|max:2048')]
    public string $newUrl = '';

    public function addBookmark(): void
    {
        $this->validate();

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
