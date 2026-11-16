<?php

namespace App\Http\Requests;

use App\Models\Country;
use App\Models\Hobby;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProfileRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->has('user_name') && ! $this->has('name')) {
            $this->merge([
                'name' => $this->input('user_name'),
            ]);
        }

        if ($this->has('country_name') && ! $this->has('country_id')) {
            $country = Country::query()
                ->where('name', $this->input('country_name'))
                ->first();

            if ($country) {
                $this->merge([
                    'country_id' => $country->id,
                ]);
            }
        }

        if ($this->has('set_your_goal') && ! $this->has('weekly_goal_minutes')) {
            $this->merge([
                'weekly_goal_minutes' => (int) round((float) $this->input('set_your_goal') * 60),
            ]);
        }



        if ($this->has('category') && ! $this->has('hobby_ids')) {
            $category = $this->input('category');

            if (is_array($category)) {
                $hobbyIds = Hobby::whereIn('name', $category)
                    ->pluck('id')
                    ->toArray();

                $this->merge(['hobby_ids' => $hobbyIds]);
            } elseif (is_string($category)) {
                $categoryNames = array_filter(array_map('trim', explode(',', $category)));

                $hobbyIds = Hobby::whereIn('name', $categoryNames)
                    ->pluck('id')
                    ->toArray();

                if (! empty($hobbyIds)) {
                    $this->merge(['hobby_ids' => $hobbyIds]);
                }
            }
        }

        if ($this->has('activities') && ! $this->has('hobby_ids')) {
            $activities = $this->input('activities');

            if (is_string($activities)) {
                $hobby = Hobby::where('name', $activities)->first();
                if ($hobby) {
                    $this->merge(['hobby_ids' => [$hobby->id]]);
                }
            } elseif (is_array($activities)) {
                $hobbyIds = Hobby::whereIn('name', $activities)
                    ->pluck('id')
                    ->toArray();

                if (! empty($hobbyIds)) {
                    $this->merge(['hobby_ids' => $hobbyIds]);
                }
            }
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
        $isCreate = $this->isMethod('post');
        $profile = $this->route('profile');
        $ignoreUserId = $profile?->user_id ?? $this->input('user_id') ?? $this->user()?->id;

        return [
            'name' => 'sometimes|string|max:255',
            'user_name' => 'sometimes|string|max:255',
            'email' => [
                'sometimes',
                'string',
                'lowercase',
                'email:rfc,dns',
                'max:255',
                Rule::unique('users', 'email')->ignore($ignoreUserId),
            ],
            'user_id' => ($isCreate ? 'required' : 'sometimes') . '|integer|exists:users,id',
            'country_id' => ($isCreate ? 'required_without:country_name' : 'sometimes') . '|integer|exists:countries,id',
            'country_name' => ($isCreate ? 'required_without:country_id' : 'sometimes') . '|string|exists:countries,name',
            'gender' => ($isCreate ? 'required' : 'sometimes') . '|in:male,female,other,prefer not to say',
            'birth_year' => ($isCreate ? 'required' : 'sometimes') . '|integer|min:1900|max:' . date('Y'),
            'set_your_goal' => ($isCreate ? 'required' : 'sometimes') . '|numeric|min:1|max:168',
            'weekly_goal_minutes' => 'sometimes|integer|min:60|max:10080',
            'category' => 'sometimes|array|min:5',
            'category.*' => 'string|exists:hobbies,name,active,1|distinct',
            'hobby_ids' => 'sometimes|array|min:5',
            'hobby_ids.*' => 'integer|exists:hobbies,id,active,1|distinct',
            'onboarding_completed' => 'sometimes|boolean',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if (! $this->filled('category')) {
                return;
            }

            $category = $this->input('category');
            $categoryNames = is_array($category) ? $category : array_filter(array_map('trim', explode(',', $category)));

            if (empty($categoryNames)) {
                $validator->errors()->add('category', 'Category must not be empty.');
                return;
            }

            $foundNames = Hobby::whereIn('name', $categoryNames)
                ->pluck('name')
                ->toArray();

            $missing = array_diff($categoryNames, $foundNames);

            if (! empty($missing)) {
                $validator->errors()->add(
                    'category',
                    'The following categories are invalid: ' . implode(', ', $missing)
                );
            }
        });
    }

    public function messages(): array
    {
        return [
            'name.string' => 'Name must be a valid string.',
            'name.max' => 'Name may not be greater than 255 characters.',
            'user_name.string' => 'User name must be a valid string.',
            'user_name.max' => 'User name may not be greater than 255 characters.',
            'email.email' => 'Email must be a valid email address.',
            'email.unique' => 'This email is already in use.',
            'user_id.required' => 'User ID is required.',
            'user_id.integer' => 'User ID must be an integer.',
            'user_id.exists' => 'The specified user does not exist.',
            'country_id.required' => 'Country ID is required.',
            'country_id.required_without' => 'Country ID is required when country name is not provided.',
            'country_id.integer' => 'Country ID must be an integer.',
            'country_id.exists' => 'Selected country is invalid.',
            'country_name.required_without' => 'Country name is required when country ID is not provided.',
            'country_name.exists' => 'Selected country name is invalid.',
            'gender.required' => 'Gender is required.',
            'birth_year.required' => 'Birth year is required.',
            'birth_year.integer' => 'Birth year must be an integer.',
            'birth_year.min' => 'Birth year must be at least 1900.',
            'birth_year.max' => 'Birth year cannot be in the future.',
            'set_your_goal.required' => 'Set your goal is required.',
            'set_your_goal.numeric' => 'Set your goal must be a valid number.',
            'set_your_goal.min' => 'Set your goal must be at least 1 hour.',
            'set_your_goal.max' => 'Set your goal must be 168 hours or less.',
            'weekly_goal_minutes.integer' => 'Weekly goal minutes must be an integer.',
            'weekly_goal_minutes.min' => 'Weekly goal minutes must be at least 60 minutes.',
            'weekly_goal_minutes.max' => 'Weekly goal minutes must be 10080 minutes or less.',
            'category.array' => 'Category must be an array.',
            'category.*.string' => 'Each category must be a string.',
            'category.*.exists' => 'Selected category is invalid.',
            'onboarding_completed.boolean' => 'Onboarding completed must be a boolean value.',
        ];
    }
}
