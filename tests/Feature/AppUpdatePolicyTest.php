<?php
namespace Tests\Feature;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppUpdatePolicyTest extends TestCase {
    use RefreshDatabase;
    private function admin(): void { $this->actingAs(Admin::create(['name'=>'Admin','email'=>'updates@example.test','password'=>bcrypt('test')]), 'admin'); }
    private function data(): array { return ['revision'=>1,'enabled'=>1,'minimum_version'=>'1.2.2','latest_version'=>'1.3.6',
        'message'=>'Please update to access the latest features, improvements and more.',
        'store_url'=>'https://play.google.com/store/apps/details?id=com.pivotirl.mobile']; }
    public function test_public_policy_is_available_before_login_but_updates_require_admin(): void {
        $this->getJson('/api/v1/app-update-policy?platform=android')->assertOk()->assertJsonPath('enabled',false);
        $this->putJson('/dashboard/app-updates/android',$this->data())->assertUnauthorized();
        $this->admin(); $this->get('/dashboard/app-updates')->assertOk();
        $this->put('/dashboard/app-updates/android',$this->data())->assertRedirect();
        $this->getJson('/api/v1/app-update-policy?platform=android')->assertOk()->assertJsonPath('minimum_version','1.2.2')
            ->assertJsonPath('latest_version','1.3.6')->assertJsonPath('revision',2)->assertJsonPath('enabled',true);
        $this->getJson('/api/v1/app-update-policy?platform=ios')->assertOk()->assertJsonPath('enabled',false);
    }
    public function test_invalid_urls_versions_and_stale_saves_are_rejected(): void {
        $this->admin();
        foreach ([['minimum_version'=>'1.2'],['latest_version'=>'1.0.0'],['store_url'=>'https://example.com'],['store_url'=>null]] as $bad)
            $this->putJson('/dashboard/app-updates/android',array_replace($this->data(),$bad))->assertUnprocessable();
        $this->putJson('/dashboard/app-updates/android',$this->data())->assertRedirect();
        $this->putJson('/dashboard/app-updates/android',$this->data())->assertUnprocessable();
        $this->putJson('/dashboard/app-updates/android',array_replace($this->data(),['revision'=>2,'enabled'=>0]))->assertRedirect();
        $this->getJson('/api/v1/app-update-policy?platform=android')->assertJsonPath('enabled',false)->assertJsonPath('revision',3);
    }
}
