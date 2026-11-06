<?php

namespace App\Http\Requests;

use App\Support\InstructionFormatter;
use Illuminate\Foundation\Http\FormRequest;

class ActivityRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $instruction = $this->input('instruction', $this->input('insruction'));

        if ($instruction !== null) {
            $this->merge([
                'instruction' => InstructionFormatter::normalize((string) $instruction),
            ]);
        }
    }

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'hobby_id' => 'nullable|exists:hobbies,id',
            'activity_title' => 'nullable|string|max:255',
            'instruction' => 'nullable|string|max:1000',
            'activity_type' => 'nullable|string|max:255',
            'subcategory' => 'nullable|string|max:255',
            'duration_minutes' => 'nullable|string|max:255',
            'tier' => 'nullable|string|max:255',
            'cost' => 'nullable|string|max:255',
            'location' => 'nullable|string|max:255',
            'age_range' => 'nullable|string|max:255',
            'neurodivergent_friendly' => 'required|in:Yes,No,Partial',
            'neurodivergent_notes' => 'nullable|string|max:1000',
            'sensory_tags' => 'nullable|string|max:255',
            'social_type' => 'nullable|string|max:255',
            'energy_level' => $this->isMethod('POST')
                ? 'required|in:Low,Medium,High'
                : 'sometimes|required|in:Low,Medium,High',
            'outcome_tag' => 'nullable|string|max:255',
            'mood_match' => 'nullable|string|max:255',
        ];
    }

    public function messages(): array
    {
        return [
            'hobby_id.exists' => 'The specified hobby does not exist.',
            'activity_title.required' => 'Activity title is required.',
            'activity_title.string' => 'Activity title must be a string.',
            'activity_title.max' => 'Activity title must not exceed 255 characters.',
            'activity_type.required' => 'Activity type is required.',
            'subcategory.required' => 'Subcategory is required.',
            'duration_minutes.required' => 'Duration is required.',
            'tier.required' => 'Tier is required.',
            'cost.required' => 'Cost is required.',
            'location.required' => 'Location is required.',
            'age_range.required' => 'Age range is required.',
            'neurodivergent_friendly.required' => 'Neurodivergent friendly status is required.',
            'neurodivergent_friendly.in' => 'Neurodivergent friendly status must be Yes, No, or Partial.',
            'energy_level.in' => 'Energy level must be Low, Medium, or High.',
            'social_type.required' => 'Social type is required.',
            'energy_level.required' => 'Energy level is required.',
            'mood_match.required' => 'Mood match is required.',
        ];
    }
}
