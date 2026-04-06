<?php

namespace App\Livewire;

use App\Jobs\ProcessBookmark;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class Home extends Component
{
    use WithPagination;

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
        $this->resetPage();
    }

    public function deleteBookmark(int $id): void
    {
        $bookmark = auth()->user()->bookmarks()->findOrFail($id);
        $bookmark->delete();
    }

    public function render(): View
    {
        /** @var LengthAwarePaginator $bookmarks */
        $bookmarks = auth()->user()
            ->bookmarks()
            ->latest()
            ->paginate(15);

        $hasPendingBookmarks = auth()->user()
            ->bookmarks()
            ->pending()
            ->exists();

        return view('livewire.home', [
            'bookmarks' => $bookmarks,
            'hasPendingBookmarks' => $hasPendingBookmarks,
        ]);
    }
}
