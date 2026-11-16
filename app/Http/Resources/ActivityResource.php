<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ActivityResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id'       => $this->id,
            'activity_title'    => $this->activity_title,
            'description' => $this->description,
            'instruction' => $this->instruction,
            'activity_type' => $this->activity_type,
            'subcategory' => $this->subcategory,
            'duration_minutes' => $this->duration_minutes,
            'tier' => $this->tier,
            'cost' => $this->cost,
            'location' => $this->location,
            'min_age' => $this->min_age,
            'max_age' => $this->max_age,
            'neurodivergent_friendly' => $this->neurodivergent_friendly,
            'neurodivergent_notes' => $this->neurodivergent_notes,
            'sensory_tags' => $this->sensory_tags,
            'social_type' => $this->social_type,
            'energy_level'   => $this->energy_level,
            'outcome_tag' => $this->outcome_tag,
            'mood_match' => $this->mood_match,
            'time' => $this->time,
            'hobby'    => [
                'id'   => $this->hobby->id,
                'name' => $this->hobby->name,
                'image_url' => $this->hobby->image_url,
            ],
        ];
    }
}
