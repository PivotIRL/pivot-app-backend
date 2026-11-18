<?php

namespace Tests\Unit;

use App\Models\Users;
use App\Services\Membership\MembershipAccessService;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class MembershipOnboardingCategoriesTest extends TestCase
{
    public function test_missing_membership_selection_uses_onboarding_categories(): void
    {
        $query = Mockery::mock(Builder::class);
        $query->shouldReceive('where')->with('user_id', 42)->once()->andReturnSelf();
        $query->shouldReceive('orderBy')->with('hobby_id')->once()->andReturnSelf();
        $query->shouldReceive('pluck')->with('hobby_id')->once()->andReturn(collect());
        DB::shouldReceive('table')->with('membership_categories')->once()->andReturn($query);

        $user = Mockery::mock(Users::class)->makePartial();
        $user->forceFill(['id' => 42]);
        $hobbies = Mockery::mock(BelongsToMany::class);
        $user->shouldReceive('hobbies')->once()->andReturn($hobbies);
        $hobbies->shouldReceive('orderByRaw')->with("CASE WHEN LOWER(name) = 'micro-movement' THEN 0 ELSE 1 END")->once()->andReturnSelf();
        $hobbies->shouldReceive('orderBy')->with('hobbies.id')->once()->andReturnSelf();
        $hobbies->shouldReceive('limit')->with(5)->once()->andReturnSelf();
        $hobbies->shouldReceive('pluck')->with('hobbies.id')->once()->andReturn(collect([9, 2, 4, 6, 8]));

        $this->assertSame(['9', '2', '4', '6', '8'], (new MembershipAccessService())->selectedCategoryIds($user, 5));
    }

    public function test_existing_membership_choices_are_preserved(): void
    {
        $query = Mockery::mock(Builder::class);
        $query->shouldReceive('where', 'orderBy')->andReturnSelf();
        $query->shouldReceive('pluck')->with('hobby_id')->once()->andReturn(collect([1, 3, 5, 7, 9]));
        DB::shouldReceive('table')->with('membership_categories')->once()->andReturn($query);
        $user = Mockery::mock(Users::class)->makePartial();
        $user->forceFill(['id' => 42]);
        $user->shouldNotReceive('hobbies');
        $this->assertSame(['1', '3', '5', '7', '9'], (new MembershipAccessService())->selectedCategoryIds($user));
    }
}
