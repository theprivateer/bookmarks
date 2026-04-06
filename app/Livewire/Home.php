<?php

namespace App\Livewire;

use App\Jobs\ProcessBookmark;
use App\Models\Tag;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
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

    public string $tagFilter = '';

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

    public function filterByTag(string $slug): void
    {
        $this->tagFilter = $slug;
        $this->resetPage();
    }

    public function clearTagFilter(): void
    {
        $this->tagFilter = '';
        $this->resetPage();
    }

    public function render(): View
    {
        /** @var LengthAwarePaginator $bookmarks */
        $bookmarks = auth()->user()
            ->bookmarks()
            ->with('tags')
            ->when(
                $this->tagFilter,
                fn ($q, $tag) => $q->whereHas('tags', fn ($t) => $t->where('slug', $tag))
            )
            ->latest()
            ->paginate(15);

        $hasPendingBookmarks = auth()->user()
            ->bookmarks()
            ->pending()
            ->exists();

        /** @var Collection $tags */
        $tags = Tag::whereHas('bookmarks', fn ($q) => $q->where('user_id', auth()->id()))
            ->withCount(['bookmarks' => fn ($q) => $q->where('user_id', auth()->id())])
            ->orderBy('name')
            ->get();

        return view('livewire.home', [
            'bookmarks' => $bookmarks,
            'hasPendingBookmarks' => $hasPendingBookmarks,
            'tags' => $tags,
        ]);
    }
}
