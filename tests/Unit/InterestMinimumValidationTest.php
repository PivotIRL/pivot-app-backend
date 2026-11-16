<?php

namespace Tests\Unit;

use App\Http\Requests\ProfileRequest;
use App\Http\Requests\UpdateActivitiesRequest;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use Illuminate\Validation\PresenceVerifierInterface;
use PHPUnit\Framework\TestCase;

class InterestMinimumValidationTest extends TestCase
{
    private function validator(): Factory
    {
        $factory = new Factory(new Translator(new ArrayLoader(), 'en'));
        $presence = $this->createMock(PresenceVerifierInterface::class);
        $presence->method('getCount')->willReturnCallback(
            fn ($table, $column, $value) => in_array($value, [1, 2, 3, 4, 5, 6, 'Gardening', 'Exercise', 'Creative Arts', 'Home Science', 'Letter Writing', 'Micro-Movement'], true) ? 1 : 0
        );
        $factory->setPresenceVerifier($presence);

        return $factory;
    }

    public function test_profile_interests_require_five_and_reject_unknown_or_duplicate_names(): void
    {
        $request = ProfileRequest::create('/profile/1', 'PATCH');
        $rules = $request->rules();
        $interests = ['Gardening', 'Exercise', 'Creative Arts', 'Home Science', 'Letter Writing', 'Micro-Movement'];
        foreach ([0, 1, 4, 5, 6] as $count) {
            $validator = $this->validator()->make(['category' => array_slice($interests, 0, $count)], $rules);
            $this->assertSame($count >= 5, $validator->passes(), $validator->errors()->toJson());
        }
        $this->assertTrue($this->validator()->make(['category' => array_fill(0, 5, 'Gardening')], $rules)->fails());
        $interests[0] = 'Unknown';
        $this->assertTrue($this->validator()->make(['category' => $interests], $rules)->fails());
        $this->assertTrue($this->validator()->make(['category' => 'Gardening'], $rules)->fails());
        $this->assertTrue($this->validator()->make(['hobby_ids' => []], $rules)->fails());
        $this->assertTrue($this->validator()->make(['hobby_ids' => [1, 2, 3, 4]], $rules)->fails());
        $this->assertTrue($this->validator()->make(['hobby_ids' => [1, 1, 1, 1, 1]], $rules)->fails());
        $this->assertTrue($this->validator()->make(['hobby_ids' => [1, 2, 3, 4, 5]], $rules)->passes());
        // Updating unrelated profile details must not require resubmitting interests.
        $this->assertTrue($this->validator()->make([], $rules)->passes());
    }

    public function test_activity_updates_require_at_least_five_interests(): void
    {
        $rules = (new UpdateActivitiesRequest())->rules();
        $interests = ['Gardening', 'Exercise', 'Creative Arts', 'Home Science', 'Letter Writing', 'Micro-Movement'];
        foreach ([0, 1, 4, 5, 6] as $count) {
            $validator = $this->validator()->make(['user_id' => 1, 'activities' => array_slice($interests, 0, $count)], $rules);
            $this->assertSame($count >= 5, $validator->passes(), $validator->errors()->toJson());
        }
        foreach ([['user_id' => 1], ['user_id' => 1, 'activities' => null], ['user_id' => 1, 'activities' => array_fill(0, 5, 'Gardening')]] as $input) {
            $this->assertTrue($this->validator()->make($input, $rules)->fails());
        }
    }
    public function test_activity_ids_accept_known_ids_and_reject_invalid_selections(): void
    {
        $rules = (new UpdateActivitiesRequest())->rules();
        $this->assertTrue($this->validator()->make(['user_id' => 1, 'category_ids' => [1, 2, 3, 4, 5]], $rules)->passes());
        foreach ([[1, 2, 3, 4], [1, 1, 2, 3, 4], [1, 2, 3, 4, 999], ['Gardening', 2, 3, 4, 5]] as $ids) {
            $this->assertTrue($this->validator()->make(['user_id' => 1, 'category_ids' => $ids], $rules)->fails());
        }
    }

}
