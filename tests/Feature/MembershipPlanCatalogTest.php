<?php

namespace Tests\Feature;

use App\Models\{Admin, User, Hobby, Research};
use App\Services\Membership\MembershipPlanCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MembershipPlanCatalogTest extends TestCase
{
    use RefreshDatabase;

    private function member(string $plan = 'standard'): User
    {
        $user = User::create(['name' => 'Plan test', 'email' => uniqid().'@example.test', 'password' => bcrypt('test-only')]);
        Sanctum::actingAs($user);
        DB::table('account_access')->insert(['user_id' => $user->id, 'selected_package' => $plan,
            'effective_package' => $plan, 'payment_path' => 'lifetime', 'access_status' => 'active',
            'access_source' => 'store', 'created_at' => now(), 'updated_at' => now()]);
        return $user;
    }

    private function admin(): void
    {
        $admin = Admin::create(['name' => 'Plan admin', 'email' => uniqid().'@example.test', 'password' => bcrypt('test-only')]);
        $this->actingAs($admin, 'admin');
    }

    private function edit(string $plan, array $changes = [], int $revision = 1)
    {
        return $this->putJson('/dashboard/membership-plans/'.$plan,
            array_replace(app(MembershipPlanCatalog::class)->limits($plan), $changes, ['revision' => $revision]));
    }

    public function test_catalog_requires_login_and_admin_page_requires_admin(): void
    {
        $this->getJson('/api/v1/membership/plans')->assertUnauthorized();
        $this->get('/dashboard/membership-plans')->assertRedirect('/dashboard/login');
        $this->member();
        $this->edit('standard', ['daily_challenges' => 99])->assertUnauthorized();
        $this->getJson('/api/v1/membership/plans')->assertOk()
            ->assertJsonPath('plans.standard.limits.daily_challenges', 3)
            ->assertJsonPath('plans.premium.limits.blocked_apps', null);
    }

    public function test_admin_update_changes_catalog_and_effective_access(): void
    {
        $this->member(); $this->admin();
        $this->get('/dashboard/membership-plans')->assertOk()->assertSee('Membership Plans');
        $this->edit('standard', ['daily_challenges' => 7, 'full_research' => true])->assertRedirect();
        $this->getJson('/api/v1/membership/plans')->assertOk()->assertJsonPath('plans.standard.revision', 2);
        $this->getJson('/api/v1/access')->assertOk()->assertJsonPath('limits.daily_challenges', 7)
            ->assertJsonPath('limits.full_research', true)->assertJsonPath('plan_catalog.plans.standard.revision', 2);
        $article = Research::create(['fun_facts' => 'Fact', 'summary' => 'Summary', 'full_content' => 'Dynamic research', 'files' => 'article.pdf']);
        $this->getJson('/api/v1/daily-article')->assertOk()->assertJsonPath('full_content', 'Dynamic research');
    }

    public function test_invalid_or_stale_edits_preserve_saved_plan(): void
    {
        $this->admin();
        $this->edit('standard', ['scheduled_blocking_hours' => 25])->assertUnprocessable();
        $this->edit('standard', ['daily_challenges' => -1])->assertUnprocessable();
        $this->edit('standard', ['hardcore' => 'yes'])->assertUnprocessable();
        $this->edit('standard', ['team_connections' => 8])->assertRedirect();
        $this->edit('standard', ['team_connections' => 99])->assertUnprocessable();
        $this->assertSame(8, app(MembershipPlanCatalog::class)->limits('standard')['team_connections']);
    }

    public function test_changed_category_count_controls_selection_validation(): void
    {
        $this->member(); $this->admin();
        $this->edit('standard', ['category_count' => 2])->assertRedirect();
        $micro = Hobby::create(['name' => 'Micro-Movement']);
        $other = Hobby::create(['name' => 'Outdoor']);
        $this->putJson('/api/v1/access/categories', ['category_ids' => [(string) $micro->id]])->assertUnprocessable();
        $this->putJson('/api/v1/access/categories', ['category_ids' => [(string) $micro->id, (string) $other->id]])
            ->assertOk()->assertJsonPath('limits.category_count', 2);
    }

    public function test_premium_can_have_finite_categories_and_disabled_full_research(): void
    {
        $this->member('premium'); $this->admin();
        $this->edit('premium', ['category_count' => 1, 'full_research' => false])->assertRedirect();
        $micro = Hobby::create(['name' => 'Micro-Movement']);
        $this->putJson('/api/v1/access/categories', ['category_ids' => [(string) $micro->id]])->assertOk();
        Research::create(['fun_facts' => 'Fact', 'summary' => 'Summary', 'full_content' => 'Hidden', 'files' => 'article.pdf']);
        $this->getJson('/api/v1/daily-article')->assertOk()->assertJsonPath('full_content', null);
    }

    public function test_plan_edits_do_not_expand_legacy_free_account_allowances(): void
    {
        $user = $this->member();
        DB::table('account_access')->where('user_id', $user->id)->delete();
        $this->admin();
        $this->edit('standard', ['daily_challenges' => 99, 'full_research' => true])->assertRedirect();
        $this->getJson('/api/v1/access')->assertOk()->assertJsonPath('effective_package', null)
            ->assertJsonPath('limits.daily_challenges', 3)->assertJsonPath('limits.full_research', false);
    }
}
