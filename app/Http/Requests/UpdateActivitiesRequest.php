<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateActivitiesRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (! $this->has('activities')) {
            return;
        }

        $activities = $this->input('activities');

        if (is_string($activities)) {
            $this->merge([
                'activities' => array_values(array_filter(array_map('trim', explode(',', $activities)))),
            ]);
        }
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user_id' => 'required|integer|exists:users,id',
            'category_ids' => 'required_without:activities|array|min:5',
            'category_ids.*' => 'required|integer|exists:hobbies,id|distinct',
            'activities' => 'required_without:category_ids|array|min:5',
            'activities.*' => 'required|string|exists:hobbies,name|distinct',
        ];
    }

    public function messages(): array
    {
        return [
            'user_id.required' => 'User ID is required.',
            'user_id.integer' => 'User ID must be an integer.',
            'user_id.exists' => 'The specified user does not exist.',
            'activities.required' => 'Please choose at least 5 interests.',
            'activities.min' => 'Please choose at least 5 interests.',
            'activities.array' => 'Activities must be an array or a comma separated string.',
            'activities.*.exists' => 'One or more activities are invalid.',
            'activities.*.distinct' => 'Duplicate activities are not allowed.',
        ];
    }
}
