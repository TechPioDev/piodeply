<?php

namespace Tests\Feature;

use App\Enums\DeploymentRing;
use App\Enums\InstallerType;
use App\Enums\JobAction;
use App\Enums\Role as RoleEnum;
use App\Livewire\Deployments\BulkDeploy;
use App\Models\Client;
use App\Models\Computer;
use App\Models\ComputerGroup;
use App\Models\ComputerSoftware;
use App\Models\Package;
use App\Models\Project;
use App\Models\User;
use App\Services\DeploymentService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Fan-out deploys: one package/action across a project, through the same
 * guarded queue as a single machine.
 */
class BulkDeployTest extends TestCase
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

    public function test_queue_bulk_queues_a_job_per_machine(): void
    {
        $project = Project::factory()->create();
        Computer::factory()->count(3)->create(['project_id' => $project->id]);
        $chrome = $this->chrome();

        $result = app(DeploymentService::class)->queueBulk(
            Computer::where('project_id', $project->id)->get(),
            $chrome,
            JobAction::Install,
        );

        $this->assertSame(3, $result->queued);
        $this->assertSame(3, $result->total);
        $this->assertSame(3, \App\Models\DeploymentJob::where('action', 'install')->count());
    }

    public function test_queue_bulk_skips_machines_already_satisfied(): void
    {
        $project = Project::factory()->create();
        $computers = Computer::factory()->count(3)->create(['project_id' => $project->id]);
        $chrome = $this->chrome();

        // One machine already has Chrome — a plain install is a no-op there.
        ComputerSoftware::factory()->create([
            'computer_id' => $computers->first()->id, 'name' => 'Google.Chrome', 'version' => '141.0', 'source' => 'winget',
        ]);

        $result = app(DeploymentService::class)->queueBulk(
            Computer::where('project_id', $project->id)->get(),
            $chrome,
            JobAction::Install,
        );

        $this->assertSame(2, $result->queued);
        $this->assertSame(1, $result->skipped);
    }

    public function test_queue_bulk_refuses_an_unsupported_action(): void
    {
        $project = Project::factory()->create();
        Computer::factory()->count(2)->create(['project_id' => $project->id]);
        $portable = Package::factory()->create(['installer_type' => InstallerType::Portable, 'winget_id' => null]);

        $result = app(DeploymentService::class)->queueBulk(
            Computer::where('project_id', $project->id)->get(),
            $portable,
            JobAction::Uninstall,
        );

        $this->assertSame(0, $result->queued);
        $this->assertSame(2, $result->refused);
    }

    public function test_component_deploys_to_a_ring_only(): void
    {
        $project = Project::factory()->create();
        Computer::factory()->count(2)->create(['project_id' => $project->id, 'ring' => DeploymentRing::Pilot]);
        Computer::factory()->create(['project_id' => $project->id, 'ring' => DeploymentRing::Production]);
        $chrome = $this->chrome();

        Livewire::actingAs($this->admin())
            ->test(BulkDeploy::class)
            ->set('projectId', $project->id)
            ->set('packageId', $chrome->id)
            ->set('ring', 'pilot')
            ->assertViewHas('targetCount', 2)
            ->call('queue');

        // Only the two pilot machines got a job.
        $this->assertSame(2, \App\Models\DeploymentJob::where('package_id', $chrome->id)->count());
    }

    public function test_component_requires_deploy_permission(): void
    {
        $viewer = User::factory()->create(); // no roles

        Livewire::actingAs($viewer)
            ->test(BulkDeploy::class)
            ->assertForbidden();
    }

    /**
     * Module 4: a device group can span clients and projects (that is the
     * whole point of a group), so targeting one is a genuinely different
     * shape of fan-out than "every machine in a project" — this proves it
     * reaches exactly the group's members and nothing else.
     */
    public function test_component_can_deploy_to_a_device_group_spanning_projects(): void
    {
        $projectA = Project::factory()->create();
        $projectB = Project::factory()->create();
        $inGroup1 = Computer::factory()->create(['project_id' => $projectA->id]);
        $inGroup2 = Computer::factory()->create(['project_id' => $projectB->id]);
        $notInGroup = Computer::factory()->create(['project_id' => $projectA->id]);

        $group = ComputerGroup::factory()->create();
        $group->computers()->attach([$inGroup1->id, $inGroup2->id]);

        $chrome = $this->chrome();

        Livewire::actingAs($this->admin())
            ->test(BulkDeploy::class)
            ->set('targetType', 'group')
            ->set('groupId', $group->id)
            ->set('packageId', $chrome->id)
            ->assertViewHas('targetCount', 2)
            ->call('queue');

        $this->assertSame(2, \App\Models\DeploymentJob::where('package_id', $chrome->id)->count());
        $this->assertDatabaseHas('deployment_jobs', ['computer_id' => $inGroup1->id, 'package_id' => $chrome->id]);
        $this->assertDatabaseHas('deployment_jobs', ['computer_id' => $inGroup2->id, 'package_id' => $chrome->id]);
        $this->assertDatabaseMissing('deployment_jobs', ['computer_id' => $notInGroup->id, 'package_id' => $chrome->id]);
    }

    /** Module 4: hand-picked machines, ignoring everything else in their projects. */
    public function test_component_can_deploy_to_hand_picked_machines(): void
    {
        $project = Project::factory()->create();
        $computers = Computer::factory()->count(3)->create(['project_id' => $project->id]);
        $chrome = $this->chrome();

        Livewire::actingAs($this->admin())
            ->test(BulkDeploy::class)
            ->set('targetType', 'machines')
            ->call('addMachine', $computers[0]->id)
            ->call('addMachine', $computers[1]->id)
            ->set('packageId', $chrome->id)
            ->assertViewHas('targetCount', 2)
            ->call('queue');

        $this->assertSame(2, \App\Models\DeploymentJob::where('package_id', $chrome->id)->count());
        $this->assertDatabaseHas('deployment_jobs', ['computer_id' => $computers[0]->id]);
        $this->assertDatabaseHas('deployment_jobs', ['computer_id' => $computers[1]->id]);
        $this->assertDatabaseMissing('deployment_jobs', ['computer_id' => $computers[2]->id, 'package_id' => $chrome->id]);
    }

    /**
     * A group is a staff concept with no tenancy of its own (it can hold any
     * client's machines) — the tenant boundary has to be enforced at
     * queue-time from the ACTOR's own visibility, or a tenant-bound user
     * could reach another client's machine simply by it sharing a group
     * with one of their own.
     */
    public function test_group_targeting_never_reaches_another_clients_machine(): void
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
        $chrome = $this->chrome();

        Livewire::actingAs($manager)
            ->test(BulkDeploy::class)
            ->set('targetType', 'group')
            ->set('groupId', $group->id)
            ->set('packageId', $chrome->id)
            ->assertViewHas('targetCount', 1) // only their own machine, not the other client's
            ->call('queue');

        $this->assertDatabaseHas('deployment_jobs', ['computer_id' => $ownComputer->id, 'package_id' => $chrome->id]);
        $this->assertDatabaseMissing('deployment_jobs', ['computer_id' => $otherComputer->id]);
    }

    /** Same boundary, the explicit-picker path: adding a foreign machine is a no-op, not an error. */
    public function test_a_tenant_bound_manager_cannot_add_another_clients_machine_to_the_picker(): void
    {
        $ownClient = Client::factory()->create();
        $otherClient = Client::factory()->create();
        $ownProject = Project::factory()->create(['client_id' => $ownClient->id]);
        $otherProject = Project::factory()->create(['client_id' => $otherClient->id]);
        $otherComputer = Computer::factory()->create(['project_id' => $otherProject->id]);

        $manager = tap(User::factory()->create(['client_id' => $ownClient->id]),
            fn (User $u) => $u->assignRole(RoleEnum::Manager->value));

        Livewire::actingAs($manager)
            ->test(BulkDeploy::class)
            ->set('targetType', 'machines')
            ->call('addMachine', $otherComputer->id)
            ->assertSet('machineIds', []);
    }
}
