<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class EventResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'date_and_time' => $this->date_and_time,
            'location' => $this->location,
            'image_url' => $this->image ? Storage::disk('public')->url($this->image) : null,
            'guests' => $this->guests ?? [],
            'redirect_link' => $this->redirect_link,
            'created_at' => $this->created_at,
        ];
    }
}
