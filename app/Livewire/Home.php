<?php

namespace App\Livewire;

use App\Jobs\AnalyseBookmark;
use App\Models\Bookmark;
use App\Models\Tag;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class Home extends Component
{
    use WithPagination;

    public string $tagFilter = '';

    public string $search = '';

    #[Url(as: 'collection')]
    public string $collectionFilter = '';

    // Edit bookmark
    public ?int $editingBookmarkId = null;

    public string $editTitle = '';

    public string $editDescription = '';

    public string $editNotes = '';

    public string $editTags = '';

    /** @var array<int> */
    public array $editCollectionIds = [];

    // Collection management
    public string $newCollectionName = '';

    public ?int $editingCollectionId = null;

    public string $editCollectionName = '';

    public function retryAnalysis(int $id): void
    {
        $bookmark = auth()->user()->bookmarks()->needsAnalysis()->findOrFail($id);

        AnalyseBookmark::dispatch($bookmark->id);
    }

    public function deleteBookmark(int $id): void
    {
        $bookmark = auth()->user()->bookmarks()->findOrFail($id);
        $bookmark->delete();
    }

    public function editBookmark(int $id): void
    {
        $bookmark = auth()->user()->bookmarks()->with('tags', 'collections')->findOrFail($id);

        $this->editingBookmarkId = $bookmark->id;
        $this->editTitle = $bookmark->title ?? '';
        $this->editDescription = $bookmark->description ?? '';
        $this->editNotes = $bookmark->notes ?? '';
        $this->editTags = $bookmark->tags->pluck('name')->implode(', ');
        $this->editCollectionIds = $bookmark->collections->pluck('id')->all();

        $this->modal('edit-bookmark')->show();
    }

    public function updateBookmark(): void
    {
        $this->validate([
            'editTitle' => 'required|string|max:255',
            'editDescription' => 'nullable|string|max:2000',
            'editNotes' => 'nullable|string|max:5000',
            'editTags' => 'nullable|string|max:500',
            'editCollectionIds' => 'array',
            'editCollectionIds.*' => 'integer',
        ]);

        $bookmark = auth()->user()->bookmarks()->findOrFail($this->editingBookmarkId);

        $bookmark->update([
            'title' => $this->editTitle,
            'description' => $this->editDescription,
            'notes' => $this->editNotes,
        ]);

        // Sync tags
        $tagNames = collect(explode(',', $this->editTags))
            ->map(fn ($t) => trim($t))
            ->filter();

        $tagIds = $tagNames->map(
            fn ($name) => Tag::firstOrCreate(
                ['slug' => Str::slug($name)],
                ['name' => $name],
            )->id
        );

        $bookmark->tags()->sync($tagIds);

        // Sync collections (validate user owns them)
        $validCollectionIds = auth()->user()->collections()
            ->whereIn('id', $this->editCollectionIds)
            ->pluck('id');

        $bookmark->collections()->sync($validCollectionIds);

        $this->modal('edit-bookmark')->close();
        $this->reset('editingBookmarkId', 'editTitle', 'editDescription', 'editNotes', 'editTags', 'editCollectionIds');
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

    public function searchBookmarks(): void
    {
        $this->validate(['search' => 'nullable|string|max:500']);
        $this->resetPage();
    }

    public function clearSearch(): void
    {
        $this->search = '';
        $this->resetPage();
    }

    public function createCollection(): void
    {
        $this->validate(['newCollectionName' => 'required|string|max:100']);

        auth()->user()->collections()->create([
            'name' => $this->newCollectionName,
            'slug' => Str::slug($this->newCollectionName),
        ]);

        $this->reset('newCollectionName');

        $this->redirect(route('home'), navigate: true);
    }

    public function editCollection(int $id): void
    {
        $collection = auth()->user()->collections()->findOrFail($id);

        $this->editingCollectionId = $collection->id;
        $this->editCollectionName = $collection->name;

        $this->modal('edit-collection')->show();
    }

    public function updateCollection(): void
    {
        $this->validate(['editCollectionName' => 'required|string|max:100']);

        $collection = auth()->user()->collections()->findOrFail($this->editingCollectionId);

        $newSlug = Str::slug($this->editCollectionName);

        $collection->update([
            'name' => $this->editCollectionName,
            'slug' => $newSlug,
        ]);

        $this->reset('editingCollectionId', 'editCollectionName');

        $this->redirect(
            route('home', $this->collectionFilter ? ['collection' => $newSlug] : []),
            navigate: true,
        );
    }

    public function deleteCollection(int $id): void
    {
        auth()->user()->collections()->findOrFail($id)->delete();

        $this->redirect(route('home'), navigate: true);
    }

    public function render(): View
    {
        $isSearching = filled($this->search) && mb_strlen($this->search) <= 500;

        $query = auth()->user()
            ->bookmarks()
            ->with('tags')
            ->when(
                $this->tagFilter,
                fn ($q, $tag) => $q->whereHas('tags', fn ($t) => $t->where('slug', $tag))
            )
            ->when(
                $this->collectionFilter,
                fn ($q, $slug) => $q->whereHas(
                    'collections',
                    fn ($c) => $c->where('slug', $slug)->where('user_id', auth()->id())
                )
            );

        /** @var LengthAwarePaginator $bookmarks */
        $bookmarks = $isSearching
            ? Bookmark::paginateCombinedSearch($query, $this->search)
            : $query->latest()->paginate(15);

        $hasPendingBookmarks = auth()->user()
            ->bookmarks()
            ->pending()
            ->exists();

        /** @var Collection $tags */
        $tags = Tag::whereHas('bookmarks', fn ($q) => $q->where('user_id', auth()->id()))
            ->withCount(['bookmarks' => fn ($q) => $q->where('user_id', auth()->id())])
            ->orderBy('name')
            ->get();

        $collections = auth()->user()->collections()
            ->withCount('bookmarks')
            ->orderBy('name')
            ->get();

        return view('livewire.home', [
            'bookmarks' => $bookmarks,
            'hasPendingBookmarks' => $hasPendingBookmarks,
            'tags' => $tags,
            'isSearching' => $isSearching,
            'collections' => $collections,
        ]);
    }
}
