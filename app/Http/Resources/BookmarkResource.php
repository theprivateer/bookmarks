<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BookmarkResource extends JsonResource
{
    /**
     * Whether to include the full extracted page text.
     *
     * Off by default, and deliberately not exposed on listings: extracted_text is a
     * longText column, so a 15-item page would ship the entire body of fifteen web
     * pages. Search listings are also built from Bookmark::LIST_COLUMNS, which omits
     * the column, so the key would read as null there even when text exists.
     */
    protected bool $includeExtractedText = false;

    /**
     * Include the extracted page text in the response. Only safe on single-bookmark
     * responses, where the model was loaded with all of its columns.
     */
    public function withExtractedText(bool $include = true): static
    {
        $this->includeExtractedText = $include;

        return $this;
    }

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'url' => $this->url,
            'domain' => $this->domain,
            'title' => $this->title,
            'description' => $this->description,
            'og_image_url' => $this->og_image_url,
            'favicon_url' => $this->favicon_url,
            'ai_summary' => $this->ai_summary,
            'extracted_text' => $this->when($this->includeExtractedText, fn () => $this->extracted_text),
            'notes' => $this->notes,
            'status' => $this->status,
            'tags' => $this->whenLoaded('tags', fn () => $this->tags->pluck('name')),
            'collections' => $this->whenLoaded('collections', fn () => $this->collections->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'slug' => $c->slug,
            ])),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'deleted_at' => $this->deleted_at,
        ];
    }
}
