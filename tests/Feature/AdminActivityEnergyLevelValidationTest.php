<?php

namespace Tests\Feature;

use App\Http\Requests\Admin\ActivityRequest;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class AdminActivityEnergyLevelValidationTest extends TestCase
{
    public function test_all_activity_fields_are_required(): void
    {
        $rules = (new ActivityRequest())->rules();
        $requiredFields = array_keys(array_filter(
            $rules,
            fn (string $rule): bool => str_contains($rule, 'required')
        ));

        $this->assertSame([
            'hobby_id',
            'activity_title',
            'instruction',
            'activity_type',
            'subcategory',
            'duration_minutes',
            'tier',
            'cost',
            'location',
            'min_age',
            'max_age',
            'neurodivergent_friendly',
            'neurodivergent_notes',
            'sensory_tags',
            'social_type',
            'energy_level',
            'outcome_tag',
            'description',
        ], $requiredFields);
        $this->assertFalse(Validator::make([], $rules)->passes());
    }

    public function test_energy_level_is_required_and_matches_the_database_enum(): void
    {
        $rules = ['energy_level' => (new ActivityRequest())->rules()['energy_level']];

        $this->assertFalse(Validator::make([], $rules)->passes());
        $this->assertFalse(Validator::make(['energy_level' => 'Very high'], $rules)->passes());

        foreach (['Low', 'Medium', 'High'] as $energyLevel) {
            $this->assertTrue(Validator::make(['energy_level' => $energyLevel], $rules)->passes());
        }
    }

    public function test_age_range_is_validated(): void
    {
        $rules = array_intersect_key(
            (new ActivityRequest())->rules(),
            array_flip(['min_age', 'max_age'])
        );

        $this->assertFalse(Validator::make(['min_age' => 16, 'max_age' => 15], $rules)->passes());
        $this->assertTrue(Validator::make(['min_age' => 16, 'max_age' => 16], $rules)->passes());
        $this->assertFalse(Validator::make(['min_age' => -1, 'max_age' => 16], $rules)->passes());
    }
}
