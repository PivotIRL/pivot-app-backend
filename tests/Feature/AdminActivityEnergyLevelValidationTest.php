<?php

namespace Tests\Feature;

use App\Http\Requests\Admin\ActivityRequest;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class AdminActivityEnergyLevelValidationTest extends TestCase
{
    public function test_only_core_activity_fields_are_required(): void
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
            'duration_minutes',
            'tier',
            'cost',
            'location',
            'min_age',
            'max_age',
            'neurodivergent_friendly',
            'sensory_tags.*',
            'social_type',
            'energy_level',
            'outcome_tag.*',
            'description',
            'mood_match.*',
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
    public function test_tag_payloads_are_serialized_for_existing_columns(): void
    {
        $request = new ActivityRequest();
        $data = ['sensory_tags' => ['Sound', 'Sight', 'Touch'], 'outcome_tag' => ['Focus', 'Social Connection'], 'mood_match' => ['Happy', 'Good']];
        $rules = array_intersect_key($request->rules(), array_flip(['sensory_tags', 'sensory_tags.*', 'outcome_tag', 'outcome_tag.*', 'mood_match', 'mood_match.*']));
        $request->setValidator(Validator::make($data, $rules));
        $saved = $request->activityData();
        $this->assertSame("['Sound', 'Sight', 'Touch']", $saved['sensory_tags']);
        $this->assertSame("['Focus', 'Social Connection']", $saved['outcome_tag']);
        $model = new \App\Models\Activity($saved);
        $this->assertSame(['Happy', 'Good'], json_decode($model->getAttributes()['mood_match'], true));
        $this->assertTrue(Validator::make([], $rules)->passes());
        $this->assertFalse(Validator::make(['sensory_tags' => ['Sound', 'Sound']], $rules)->passes());
    }

    public function test_new_activity_requires_at_least_one_mood(): void
    {
        $request = ActivityRequest::create('/admin/activities', 'POST');
        $rules = array_intersect_key($request->rules(), array_flip(['mood_match', 'mood_match.*']));
        foreach ([[], ['mood_match' => null], ['mood_match' => []]] as $data) {
            $this->assertFalse(Validator::make($data, $rules)->passes());
        }
        $this->assertTrue(Validator::make(['mood_match' => ['Happy']], $rules)->passes());
        $edit = ActivityRequest::create('/admin/activities/1', 'PUT');
        $this->assertTrue(Validator::make([], ['mood_match' => $edit->rules()['mood_match']])->passes());
    }

}
