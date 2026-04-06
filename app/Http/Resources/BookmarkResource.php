<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BookmarkResource extends JsonResource
{
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
            'status' => $this->status,
            'tags' => $this->whenLoaded('tags', fn () => $this->tags->pluck('name')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'deleted_at' => $this->deleted_at,
        ];
    }
}
