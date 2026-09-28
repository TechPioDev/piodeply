<?php

namespace Tests\Feature;

use App\Enums\Role as RoleEnum;
use App\Livewire\Policies\PolicyForm;
use App\Models\Client;
use App\Models\Computer;
use App\Models\ComputerGroup;
use App\Models\ComputerSoftware;
use App\Models\DeploymentJob;
use App\Models\Package;
use App\Models\Project;
use App\Models\SoftwarePolicy;
use App\Models\User;
use App\Services\PolicyService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Module 7: assignment scopes for software policies (project/group/computer),
 * reusing the exact scope_type/scope_id pattern Browser Policies already
 * proved (see BrowserPolicyScopeTest). The one thing genuinely new here,
 * beyond what Browser Policies needed: a group- or computer-scoped policy
 * has to be found by enforceForComputer() -- the real-time, per-check-in
 * path -- not just by the project_id lookup it used before, or such a
 * policy would sit invisible until the next scheduled sweep.
 */
class PolicyScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function admin(): User
    {
        return tap(User::factory()->create(), fn (User $u) => $u->assignRole(RoleEnum::Admin->value));
    }

    private function chrome(): Package
    {
        return Package::factory()->create(['name' => 'Google Chrome', 'winget_id' => 'Google.Chrome']);
    }

    public function test_legacy_project_writers_get_a_scope_automatically(): void
    {
        // The factory (like PolicyTemplateService and the old form) sets only project_id.
        $policy = SoftwarePolicy::factory()->create();

        $this->assertSame('project', $policy->scope_type);
        $this->assertSame($policy->project_id, $policy->scope_id);
    }

    public function test_group_scope_reaches_members_only(): void
    {
        $member = Computer::factory()->create();
        $outsider = Computer::factory()->create();
        $group = ComputerGroup::factory()->create();
        $group->computers()->attach($member);

        $policy = SoftwarePolicy::factory()->create([
            'project_id' => null, 'scope_type' => 'group', 'scope_id' => $group->id,
            'package_id' => $this->chrome()->id,
        ]);

        $ids = $policy->targetComputers()->pluck('id')->all();
        $this->assertSame([$member->id], $ids);
        $this->assertNotContains($outsider->id, $ids);
    }

    public function test_computer_scope_targets_exactly_that_machine(): void
    {
        $target = Computer::factory()->create();
        $other = Computer::factory()->create();

        $policy = SoftwarePolicy::factory()->create([
            'project_id' => null, 'scope_type' => 'computer', 'scope_id' => $target->id,
            'package_id' => $this->chrome()->id,
        ]);

        $this->assertSame([$target->id], $policy->targetComputers()->pluck('id')->all());
        $this->assertTrue($policy->coversComputer($target));
        $this->assertFalse($policy->coversComputer($other));
    }

    /**
     * The actual bug Module 7 has to close: before scopeQueryForComputer(),
     * enforceForComputer() only ever looked up SoftwarePolicy::where(
     * 'project_id', $computer->project_id) -- a group- or computer-scoped
     * policy would never fire on a real agent check-in, only picked up
     * later by the scheduled enforceAll() sweep. This proves check-in alone
     * (no sweep) queues the job.
     */
    public function test_a_group_scoped_policy_enforces_on_check_in_not_just_the_scheduled_sweep(): void
    {
        $member = Computer::factory()->create();
        $group = ComputerGroup::factory()->create();
        $group->computers()->attach($member);
        $chrome = $this->chrome();

        SoftwarePolicy::factory()->create([
            'project_id' => null, 'scope_type' => 'group', 'scope_id' => $group->id,
            'package_id' => $chrome->id, 'action' => 'install', 'mode' => 'enforce',
        ]);

        $queued = app(PolicyService::class)->enforceForComputer($member->fresh());

        $this->assertSame(1, $queued);
        $this->assertDatabaseHas('deployment_jobs', ['computer_id' => $member->id, 'package_id' => $chrome->id]);
    }

    /** Same fix, the computer-scope side. */
    public function test_a_computer_scoped_policy_enforces_on_check_in(): void
    {
        $target = Computer::factory()->create();
        $chrome = $this->chrome();

        SoftwarePolicy::factory()->create([
            'project_id' => null, 'scope_type' => 'computer', 'scope_id' => $target->id,
            'package_id' => $chrome->id, 'action' => 'install', 'mode' => 'enforce',
        ]);

        $queued = app(PolicyService::class)->enforceForComputer($target->fresh());

        $this->assertSame(1, $queued);
        $this->assertDatabaseHas('deployment_jobs', ['computer_id' => $target->id, 'package_id' => $chrome->id]);
    }

    /** explainFor() is the computer-centric inverse of enforceForComputer() — same gap, same fix. */
    public function test_explain_for_finds_group_scoped_policies_too(): void
    {
        $member = Computer::factory()->create();
        $group = ComputerGroup::factory()->create();
        $group->computers()->attach($member);
        $chrome = $this->chrome();

        $policy = SoftwarePolicy::factory()->create([
            'project_id' => null, 'scope_type' => 'group', 'scope_id' => $group->id,
            'package_id' => $chrome->id,
        ]);

        $explained = app(PolicyService::class)->explainFor($member->fresh());

        $this->assertTrue($explained->contains(fn (array $row) => $row['policy']->is($policy)));
    }

    public function test_same_scope_and_package_and_action_is_a_conflict_but_across_scopes_is_not(): void
    {
        $project = Project::factory()->create();
        $chrome = $this->chrome();
        SoftwarePolicy::factory()->create(['project_id' => $project->id, 'package_id' => $chrome->id, 'action' => 'install']);

        // Same project + package + action -> refused as a duplicate, not a 500.
        Livewire::actingAs($this->admin())
            ->test(PolicyForm::class)
            ->set('scope_type', 'project')
            ->set('project_id', $project->id)
            ->set('packageIds', [$chrome->id])
            ->set('action', 'install')
            ->call('save')
            ->assertHasErrors('packageIds');

        // Same package + action, but a DIFFERENT scope (a group) -> allowed.
        $group = ComputerGroup::factory()->create();
        Livewire::actingAs($this->admin())
            ->test(PolicyForm::class)
            ->set('scope_type', 'group')
            ->set('scope_id', $group->id)
            ->set('packageIds', [$chrome->id])
            ->set('action', 'install')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('software_policies', ['scope_type' => 'group', 'scope_id' => $group->id, 'package_id' => $chrome->id]);
    }

    public function test_tenants_see_their_own_group_scoped_policy_but_not_a_foreign_one(): void
    {
        $client = Client::factory()->create();
        $project = Project::factory()->create(['client_id' => $client->id]);
        $ownComputer = Computer::factory()->create(['project_id' => $project->id]);
        $group = ComputerGroup::factory()->create();
        $group->computers()->attach($ownComputer);

        $own = SoftwarePolicy::factory()->create([
            'project_id' => null, 'scope_type' => 'group', 'scope_id' => $group->id,
            'package_id' => $this->chrome()->id,
        ]);
        $foreign = SoftwarePolicy::factory()->create(); // another client's project entirely

        $visible = SoftwarePolicy::visibleTo($client->id)->pluck('id');
        $this->assertTrue($visible->contains($own->id));
        $this->assertFalse($visible->contains($foreign->id));
    }

    /**
     * The cross-tenant leak Module 7 has to prevent at creation time: a
     * group can span clients by design, so a tenant picking one that
     * includes a foreign machine must be refused — enforcement runs
     * unattended later with no per-request actor left to check against.
     */
    public function test_a_tenant_cannot_create_a_group_scoped_policy_reaching_another_clients_machine(): void
    {
        $ownClient = Client::factory()->create();
        $otherClient = Client::factory()->create();
        $ownProject = Project::factory()->create(['client_id' => $ownClient->id]);
        $otherProject = Project::factory()->create(['client_id' => $otherClient->id]);
        $ownComputer = Computer::factory()->create(['project_id' => $ownProject->id]);
        $otherComputer = Computer::factory()->create(['project_id' => $otherProject->id]);

        $group = ComputerGroup::factory()->create();
        $group->computers()->attach([$ownComputer->id, $otherComputer->id]);

        $manager = tap(User::factory()->create(['client_id' => $ownClient->id]),
            fn (User $u) => $u->assignRole(RoleEnum::Manager->value));

        Livewire::actingAs($manager)
            ->test(PolicyForm::class)
            ->set('scope_type', 'group')
            ->set('scope_id', $group->id)
            ->set('packageIds', [$this->chrome()->id])
            ->call('save')
            ->assertHasErrors('scope_id');

        $this->assertDatabaseMissing('software_policies', ['scope_type' => 'group', 'scope_id' => $group->id]);
    }

    public function test_fleet_summary_counts_group_scoped_policies_for_the_tenant_they_belong_to(): void
    {
        $client = Client::factory()->create();
        $project = Project::factory()->create(['client_id' => $client->id]);
        $computer = Computer::factory()->create(['project_id' => $project->id]);
        $group = ComputerGroup::factory()->create();
        $group->computers()->attach($computer);

        SoftwarePolicy::factory()->create([
            'project_id' => null, 'scope_type' => 'group', 'scope_id' => $group->id,
            'package_id' => $this->chrome()->id, 'mode' => 'enforce',
        ]);

        $summary = app(PolicyService::class)->fleetSummary($client->id);
        $this->assertSame(1, $summary['policies']);
        $this->assertSame(1, $summary['target']);

        // Nothing leaks into a client this group has no machine under.
        $otherClient = Client::factory()->create();
        $this->assertSame(0, app(PolicyService::class)->fleetSummary($otherClient->id)['policies']);
    }
}
