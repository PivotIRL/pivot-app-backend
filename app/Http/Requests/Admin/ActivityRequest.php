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
            'instruction'             => 'required|string',
            'activity_type'           => 'required|string|max:255',
            'subcategory'             => 'required|string|max:255',
            'duration_minutes'        => 'required|integer|min:1',
            'tier'                    => 'required|in:1,2,3',
            'cost'                    => 'required|string|max:255',
            'location'                => 'required|string|max:255',
            'min_age'                 => 'required|integer|min:0|max:120',
            'max_age'                 => 'required|integer|min:0|max:120|gte:min_age',
            'neurodivergent_friendly' => 'required|in:Yes,No,Partial',
            'neurodivergent_notes'    => 'required|string',
            'sensory_tags'            => 'required|string|max:255',
            'social_type'             => 'required|string|max:255',
            'energy_level'            => 'required|in:Low,Medium,High',
            'outcome_tag'             => 'required|string|max:255',
            'description'             => 'required|string',
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
            'instruction.required' => 'Please enter instructions for this activity.',
            'instruction.string' => 'Instructions must be entered as text.',
            'activity_type.required' => 'Please enter an activity type.',
            'activity_type.string' => 'Activity type must be entered as text.',
            'activity_type.max' => 'Activity type may not be longer than 255 characters.',
            'subcategory.required' => 'Please enter a subcategory.',
            'subcategory.string' => 'Subcategory must be entered as text.',
            'subcategory.max' => 'Subcategory may not be longer than 255 characters.',
            'duration_minutes.required' => 'Please enter the activity duration.',
            'duration_minutes.integer' => 'Duration must be a whole number of minutes.',
            'duration_minutes.min' => 'Duration must be at least one minute.',
            'tier.required' => 'Please select a tier for this activity.',
            'tier.in' => 'Please select a valid tier: Tier 1, Tier 2, or Tier 3.',
            'cost.required' => 'Please enter the activity cost.',
            'location.required' => 'Please enter the activity location.',
            'min_age.required' => 'Please enter a minimum age.',
            'min_age.integer' => 'Minimum age must be a whole number.',
            'min_age.min' => 'Minimum age cannot be negative.',
            'min_age.max' => 'Minimum age cannot be greater than 120.',
            'max_age.required' => 'Please enter a maximum age.',
            'max_age.integer' => 'Maximum age must be a whole number.',
            'max_age.min' => 'Maximum age cannot be negative.',
            'max_age.max' => 'Maximum age cannot be greater than 120.',
            'max_age.gte' => 'Maximum age must be greater than or equal to minimum age.',
            'neurodivergent_friendly.required' => 'Please select a neurodivergent-friendly value.',
            'neurodivergent_friendly.in' => 'Please select Yes, No, or Partial.',
            'neurodivergent_notes.required' => 'Please enter neurodivergent notes.',
            'sensory_tags.required' => 'Please enter sensory tags.',
            'social_type.required' => 'Please enter a social type.',
            'outcome_tag.required' => 'Please enter an outcome tag.',
            'energy_level.required' => 'Please select an energy level for this activity.',
            'energy_level.in' => 'Please select a valid energy level: Low, Medium, or High.',
            'sensory_tags.string' => 'Sensory tags must be entered as text.',
            'sensory_tags.max' => 'Sensory tags may not be longer than 255 characters.',
            'social_type.string' => 'Social type must be entered as text.',
            'social_type.max' => 'Social type may not be longer than 255 characters.',
            'outcome_tag.string' => 'Outcome tag must be entered as text.',
            'outcome_tag.max' => 'Outcome tag may not be longer than 255 characters.',
            'description.required' => 'Please enter a description.',
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
            'min_age' => 'minimum age',
            'max_age' => 'maximum age',
            'neurodivergent_friendly' => 'neurodivergent friendly',
            'neurodivergent_notes' => 'neurodivergent notes',
            'sensory_tags' => 'sensory tags',
            'social_type' => 'social type',
            'energy_level' => 'energy level',
            'outcome_tag' => 'outcome tag',
            'description' => 'description',
        ];
    }
}
