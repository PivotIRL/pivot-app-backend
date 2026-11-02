<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ActivityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'hobby_id'                => 'required|integer|exists:hobbies,id',
            'activity_title'          => 'required|string|max:255',
            'instruction'             => 'nullable|string',
            'activity_type'           => 'nullable|string|max:255',
            'subcategory'             => 'nullable|string|max:255',
            'duration_minutes'        => 'nullable|string|max:255',
            'tier'                    => 'required|in:1,2,3',
            'cost'                    => 'nullable|string|max:255',
            'location'                => 'nullable|string|max:255',
            'age_range'               => 'nullable|string|max:255',
            'min_age'                 => 'nullable|integer',
            'max_age'                 => 'nullable|integer',
            'neurodivergent_friendly' => 'nullable|string',
            'neurodivergent_notes'    => 'nullable|string',
            'sensory_tags'            => 'nullable|string|max:255',
            'social_type'             => 'nullable|string|max:255',
            'energy_level'            => 'nullable|string|max:255',
            'outcome_tag'             => 'nullable|string|max:255',
            'time'                    => 'nullable|string|max:255',
            'status'                  => 'nullable|string|max:255',
            'description'             => 'nullable|string',
        ];
    }

    public function messages(): array
    {
        return [
            'hobby_id.required' => 'Please select a category for this activity.',
            'hobby_id.integer' => 'Please select a valid category.',
            'hobby_id.exists' => 'The selected category is invalid. Please choose another category.',
            'activity_title.required' => 'Please enter an activity title.',
            'activity_title.string' => 'The activity title must be text.',
            'activity_title.max' => 'The activity title may not be longer than 255 characters.',
            'instruction.string' => 'Instructions must be entered as text.',
            'activity_type.string' => 'Activity type must be entered as text.',
            'activity_type.max' => 'Activity type may not be longer than 255 characters.',
            'subcategory.string' => 'Subcategory must be entered as text.',
            'subcategory.max' => 'Subcategory may not be longer than 255 characters.',
            'duration_minutes.string' => 'Duration must be entered as text.',
            'duration_minutes.max' => 'Duration may not be longer than 255 characters.',
            'tier.required' => 'Please select a tier for this activity.',
            'tier.in' => 'Please select a valid tier: Tier 1, Tier 2, or Tier 3.',
            'cost.string' => 'Cost must be entered as text.',
            'cost.max' => 'Cost may not be longer than 255 characters.',
            'location.string' => 'Location must be entered as text.',
            'location.max' => 'Location may not be longer than 255 characters.',
            'age_range.string' => 'Age range must be entered as text.',
            'age_range.max' => 'Age range may not be longer than 255 characters.',
            'min_age.integer' => 'Minimum age must be a whole number.',
            'max_age.integer' => 'Maximum age must be a whole number.',
            'neurodivergent_friendly.string' => 'Neurodivergent Friendly must be a valid selection.',
            'neurodivergent_notes.string' => 'Neurodivergent notes must be entered as text.',
            'sensory_tags.string' => 'Sensory tags must be entered as text.',
            'sensory_tags.max' => 'Sensory tags may not be longer than 255 characters.',
            'social_type.string' => 'Social type must be entered as text.',
            'social_type.max' => 'Social type may not be longer than 255 characters.',
            'energy_level.string' => 'Energy level must be entered as text.',
            'energy_level.max' => 'Energy level may not be longer than 255 characters.',
            'outcome_tag.string' => 'Outcome tag must be entered as text.',
            'outcome_tag.max' => 'Outcome tag may not be longer than 255 characters.',
            'time.string' => 'Time must be entered as text.',
            'time.max' => 'Time may not be longer than 255 characters.',
            'status.string' => 'Status must be entered as text.',
            'status.max' => 'Status may not be longer than 255 characters.',
            'description.string' => 'Description must be entered as text.',
        ];
    }

    public function attributes(): array
    {
        return [
            'hobby_id' => 'category',
            'activity_title' => 'activity title',
            'tier' => 'tier',
            'instruction' => 'instructions',
            'activity_type' => 'activity type',
            'subcategory' => 'subcategory',
            'duration_minutes' => 'duration',
            'cost' => 'cost',
            'location' => 'location',
            'age_range' => 'age range',
            'min_age' => 'minimum age',
            'max_age' => 'maximum age',
            'neurodivergent_friendly' => 'neurodivergent friendly',
            'neurodivergent_notes' => 'neurodivergent notes',
            'sensory_tags' => 'sensory tags',
            'social_type' => 'social type',
            'energy_level' => 'energy level',
            'outcome_tag' => 'outcome tag',
            'time' => 'time',
            'status' => 'status',
            'description' => 'description',
        ];
    }
}
