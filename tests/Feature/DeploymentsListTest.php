<?php

namespace Tests\Feature;

use App\Enums\JobAction;
use App\Enums\JobStatus;
use App\Enums\Role as RoleEnum;
use App\Livewire\Deployments\DeploymentsIndex;
use App\Models\Computer;
use App\Models\DeploymentJob;
use App\Models\Package;
use App\Models\PackageVersion;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DeploymentsListTest extends TestCase
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

    public function test_retry_all_failed_requeues_the_fleet_in_one_click(): void
    {
        $computer = Computer::factory()->create();
        $package = Package::factory()->create();

        // Three genuinely failed jobs...
        DeploymentJob::factory()->count(3)->create([
            'computer_id' => $computer->id, 'package_id' => $package->id,
            'action' => JobAction::Uninstall, 'status' => JobStatus::Failed,
            'attempts' => 3, 'exit_code' => -1978335210,
            'failure_reason' => 'winget exited with -1978335210.',
        ]);
        // ...one that already succeeded (must not be touched)...
        $ok = DeploymentJob::factory()->create([
            'computer_id' => $computer->id, 'package_id' => $package->id,
            'action' => JobAction::Install, 'status' => JobStatus::Succeeded,
        ]);
        // ...and one whose failure is in the job itself: a rollback with no
        // target version can never succeed, so it is skipped, not looped.
        $impossible = DeploymentJob::factory()->create([
            'computer_id' => $computer->id, 'package_id' => $package->id,
            'action' => JobAction::Rollback, 'status' => JobStatus::Failed,
            'target_version' => null, 'package_version_id' => null,
        ]);

        Livewire::actingAs($this->admin())
            ->test(DeploymentsIndex::class)
            ->call('retryAllFailed');

        $this->assertSame(3, DeploymentJob::where('status', JobStatus::Pending)->count());
        $this->assertSame(JobStatus::Succeeded, $ok->fresh()->status, 'succeeded jobs are left alone');
        $this->assertSame(JobStatus::Failed, $impossible->fresh()->status, 'impossible jobs are skipped, not requeued');

        // Requeued jobs start clean — attempts reset, old failure cleared.
        $requeued = DeploymentJob::where('status', JobStatus::Pending)->first();
        $this->assertSame(0, $requeued->attempts);
        $this->assertNull($requeued->failure_reason);
    }

    public function test_retry_all_failed_never_reaches_another_tenants_jobs(): void
    {
        $mine = \App\Models\Client::factory()->create();
        $myMachine = Computer::factory()->create([
            'project_id' => \App\Models\Project::factory()->create(['client_id' => $mine->id])->id,
        ]);
        $foreignMachine = Computer::factory()->create(); // another client entirely
        $package = Package::factory()->create();

        $myJob = DeploymentJob::factory()->create([
            'computer_id' => $myMachine->id, 'package_id' => $package->id, 'status' => JobStatus::Failed,
        ]);
        $foreignJob = DeploymentJob::factory()->create([
            'computer_id' => $foreignMachine->id, 'package_id' => $package->id, 'status' => JobStatus::Failed,
        ]);

        $owner = tap(User::factory()->create(['client_id' => $mine->id]),
            fn (User $u) => $u->assignRole(RoleEnum::ClientOwner->value));

        Livewire::actingAs($owner)
            ->test(DeploymentsIndex::class)
            ->call('retryAllFailed');

        $this->assertSame(JobStatus::Pending, $myJob->fresh()->status);
        $this->assertSame(JobStatus::Failed, $foreignJob->fresh()->status, 'a bulk action cannot reach further than the eye can see');
    }

    public function test_repeated_jobs_collapse_to_one_row_with_a_repeat_count(): void
    {
        $computer = Computer::factory()->create(['hostname' => 'SUSHMITA-L11']);
        $package = Package::factory()->create(['name' => 'Google Chrome', 'winget_id' => 'Google.Chrome']);

        // The mess that exists today: the same task, over and over.
        DeploymentJob::factory()->count(6)->create([
            'computer_id' => $computer->id,
            'package_id'  => $package->id,
            'action'      => JobAction::Install,
            'status'      => JobStatus::Succeeded,
        ]);

        Livewire::actingAs($this->admin())
            ->test(DeploymentsIndex::class)
            ->assertViewHas('jobs', fn ($jobs) => $jobs->total() === 1 && $jobs->first()->repeat_count === 6)
            ->assertSee('×6');
    }

    /** The computer page had its own flat list, which kept showing the mess. */
    public function test_a_computers_recent_deployments_panel_collapses_too(): void
    {
        $computer = Computer::factory()->create();
        $package = Package::factory()->create(['name' => 'Google Chrome', 'winget_id' => 'Google.Chrome']);

        DeploymentJob::factory()->count(8)->create([
            'computer_id' => $computer->id,
            'package_id'  => $package->id,
            'action'      => JobAction::Install,
            'status'      => JobStatus::Succeeded,
        ]);

        Livewire::actingAs($this->admin())
            ->test(\App\Livewire\Computers\ComputerShow::class, ['computer' => $computer])
            ->assertViewHas('recentJobs', fn ($jobs) => $jobs->count() === 1 && $jobs->first()->repeat_count === 8)
            ->assertSee('×8');
    }

    /**
     * A rollback with no pinned version cannot succeed however many times it
     * runs — the failure is in the job, not the machine.
     */
    public function test_a_job_that_cannot_succeed_is_not_offered_a_retry(): void
    {
        $computer = Computer::factory()->create();
        $package = Package::factory()->create(['winget_id' => 'Google.Chrome']);

        $job = DeploymentJob::factory()->create([
            'computer_id'    => $computer->id,
            'package_id'     => $package->id,
            'action'         => JobAction::Rollback,
            'target_version' => null,
            'status'         => JobStatus::Failed,
            'attempts'       => 3,
            'max_attempts'   => 3,
        ]);

        $this->assertNotNull($job->impossibleReason());

        Livewire::actingAs($this->admin())
            ->test(DeploymentsIndex::class)
            ->assertSee('nothing to roll back to')
            ->assertDontSee('label="Retry"', false);
    }

    public function test_retrying_an_impossible_job_explains_instead_of_requeueing(): void
    {
        $computer = Computer::factory()->create();
        $job = DeploymentJob::factory()->create([
            'computer_id'    => $computer->id,
            'package_id'     => Package::factory()->create(['winget_id' => 'Google.Chrome'])->id,
            'action'         => JobAction::Rollback,
            'target_version' => null,
            'status'         => JobStatus::Failed,
            'attempts'       => 3,
            'max_attempts'   => 3,
        ]);

        Livewire::actingAs($this->admin())
            ->test(DeploymentsIndex::class)
            ->call('retry', $job->id);

        // Still failed, still out of attempts — not quietly re-queued to fail again.
        $job->refresh();
        $this->assertSame(JobStatus::Failed, $job->status);
        $this->assertSame(3, $job->attempts);
    }

    public function test_an_ordinary_failure_is_still_retryable(): void
    {
        $computer = Computer::factory()->create();
        $job = DeploymentJob::factory()->create([
            'computer_id'  => $computer->id,
            'package_id'   => Package::factory()->create(['winget_id' => 'Google.Chrome'])->id,
            'action'       => JobAction::Install,
            'status'       => JobStatus::Failed,
            'attempts'     => 3,
            'max_attempts' => 3,
        ]);

        $this->assertNull($job->impossibleReason());

        Livewire::actingAs($this->admin())
            ->test(DeploymentsIndex::class)
            ->call('retry', $job->id);

        $job->refresh();
        $this->assertSame(JobStatus::Pending, $job->status);
        $this->assertSame(0, $job->attempts);
    }

    /** The cards must count what the table is actually showing -- folded tasks, not every historical attempt. */
    public function test_summary_cards_count_folded_tasks_by_default(): void
    {
        $computer = Computer::factory()->create();
        $package = Package::factory()->create();

        // Same task, retried twice: one failed attempt superseded by a
        // succeeded one. Folded, this is 1 succeeded task, not 2 jobs.
        DeploymentJob::factory()->create([
            'computer_id' => $computer->id, 'package_id' => $package->id,
            'action' => JobAction::Install, 'status' => JobStatus::Failed,
        ]);
        DeploymentJob::factory()->create([
            'computer_id' => $computer->id, 'package_id' => $package->id,
            'action' => JobAction::Install, 'status' => JobStatus::Succeeded,
        ]);
        // A different, still-pending task.
        DeploymentJob::factory()->create([
            'computer_id' => $computer->id, 'package_id' => Package::factory()->create()->id,
            'action' => JobAction::Install, 'status' => JobStatus::Pending,
        ]);

        Livewire::actingAs($this->admin())
            ->test(DeploymentsIndex::class)
            ->assertViewHas('stats', fn (array $s) => $s['total'] === 2
                && $s['succeeded'] === 1
                && $s['failed'] === 0
                && $s['in_flight'] === 1);
    }

    public function test_the_in_flight_card_filters_to_pending_blocked_and_running(): void
    {
        $computer = Computer::factory()->create();

        $pending = DeploymentJob::factory()->create([
            'computer_id' => $computer->id, 'package_id' => Package::factory()->create(['name' => 'PendingPkg'])->id,
            'status' => JobStatus::Pending,
        ]);
        $succeeded = DeploymentJob::factory()->create([
            'computer_id' => $computer->id, 'package_id' => Package::factory()->create(['name' => 'SucceededPkg'])->id,
            'status' => JobStatus::Succeeded,
        ]);

        Livewire::actingAs($this->admin())
            ->test(DeploymentsIndex::class)
            ->set('status', 'in_flight')
            ->assertSee('PendingPkg')
            ->assertDontSee('SucceededPkg');
    }

    public function test_full_history_shows_every_attempt(): void
    {
        $computer = Computer::factory()->create();
        $package = Package::factory()->create(['winget_id' => 'Google.Chrome']);

        DeploymentJob::factory()->count(6)->create([
            'computer_id' => $computer->id,
            'package_id'  => $package->id,
            'action'      => JobAction::Install,
            'status'      => JobStatus::Succeeded,
        ]);

        Livewire::actingAs($this->admin())
            ->test(DeploymentsIndex::class)
            ->set('history', true)
            ->assertViewHas('jobs', fn ($jobs) => $jobs->total() === 6);
    }

    public function test_collapsing_keeps_the_newest_job_of_each_task(): void
    {
        $computer = Computer::factory()->create();
        $package = Package::factory()->create(['winget_id' => 'Google.Chrome']);

        DeploymentJob::factory()->create([
            'computer_id' => $computer->id, 'package_id' => $package->id,
            'action' => JobAction::Install, 'status' => JobStatus::Failed,
        ]);
        $newest = DeploymentJob::factory()->create([
            'computer_id' => $computer->id, 'package_id' => $package->id,
            'action' => JobAction::Install, 'status' => JobStatus::Succeeded,
        ]);

        Livewire::actingAs($this->admin())
            ->test(DeploymentsIndex::class)
            ->assertViewHas('jobs', fn ($jobs) => $jobs->first()->id === $newest->id);
    }

    public function test_status_is_bound_to_the_url_for_deep_linking(): void
    {
        // The dashboard's "Failed deployments" health-issue row links to
        // ?status=failed; the property must hydrate from the query string.
        $computer = Computer::factory()->create();
        DeploymentJob::factory()->create([
            'computer_id' => $computer->id, 'package_id' => Package::factory()->create(['name' => 'FailedPkg'])->id,
            'status' => JobStatus::Failed,
        ]);
        DeploymentJob::factory()->create([
            'computer_id' => $computer->id, 'package_id' => Package::factory()->create(['name' => 'OkPkg'])->id,
            'status' => JobStatus::Succeeded,
        ]);

        Livewire::withQueryParams(['status' => 'failed'])
            ->actingAs($this->admin())
            ->test(DeploymentsIndex::class)
            ->assertSet('status', 'failed')
            ->assertSee('FailedPkg')
            ->assertDontSee('OkPkg');
    }

    public function test_different_actions_on_one_package_stay_separate_rows(): void
    {
        $computer = Computer::factory()->create();
        $package = Package::factory()->create(['winget_id' => 'Google.Chrome']);

        DeploymentJob::factory()->create([
            'computer_id' => $computer->id, 'package_id' => $package->id, 'action' => JobAction::Install,
        ]);
        DeploymentJob::factory()->create([
            'computer_id' => $computer->id, 'package_id' => $package->id, 'action' => JobAction::Uninstall,
        ]);

        Livewire::actingAs($this->admin())
            ->test(DeploymentsIndex::class)
            ->assertViewHas('jobs', fn ($jobs) => $jobs->total() === 2);
    }

    public function test_the_list_shows_the_version_transition(): void
    {
        $computer = Computer::factory()->create();
        $package = Package::factory()->create(['winget_id' => 'Google.Chrome']);

        DeploymentJob::factory()->create([
            'computer_id'              => $computer->id,
            'package_id'               => $package->id,
            'action'                   => JobAction::Install,
            'installed_version_before' => '138.0',
            'target_version'           => '141.0',
        ]);

        Livewire::actingAs($this->admin())
            ->test(DeploymentsIndex::class)
            ->assertSee('138.0 → 141.0');
    }

    /** The display bug: a pinned winget target rendered as a blank version. */
    public function test_a_pinned_target_version_is_no_longer_blank(): void
    {
        $computer = Computer::factory()->create();
        $package = Package::factory()->create(['winget_id' => 'Google.Chrome']);

        DeploymentJob::factory()->create([
            'computer_id'        => $computer->id,
            'package_id'         => $package->id,
            'action'             => JobAction::Install,
            'target_version'     => '141.0',
            'package_version_id' => null, // the winget case
        ]);

        Livewire::actingAs($this->admin())
            ->test(DeploymentsIndex::class)
            ->assertSee('141.0');
    }

    public function test_the_catalogue_version_is_used_when_no_target_is_pinned(): void
    {
        $computer = Computer::factory()->create();
        $package = Package::factory()->create(['winget_id' => null, 'choco_id' => null]);
        $version = PackageVersion::factory()->create(['package_id' => $package->id, 'version' => '9.9.9']);

        DeploymentJob::factory()->create([
            'computer_id'        => $computer->id,
            'package_id'         => $package->id,
            'action'             => JobAction::Install,
            'target_version'     => null,
            'package_version_id' => $version->id,
        ]);

        Livewire::actingAs($this->admin())
            ->test(DeploymentsIndex::class)
            ->assertSee('9.9.9');
    }

    public function test_an_uninstall_shows_no_destination_version(): void
    {
        $computer = Computer::factory()->create();
        $package = Package::factory()->create(['winget_id' => 'Google.Chrome']);

        $job = DeploymentJob::factory()->create([
            'computer_id'              => $computer->id,
            'package_id'               => $package->id,
            'action'                   => JobAction::Uninstall,
            'installed_version_before' => '141.0',
        ]);

        $this->assertSame('141.0', $job->versionLabel());
    }

    public function test_an_unknown_target_reads_as_latest(): void
    {
        $job = DeploymentJob::factory()->make([
            'action'                   => JobAction::Update,
            'installed_version_before' => '138.0',
            'target_version'           => null,
            'package_version_id'       => null,
        ]);

        $this->assertSame('138.0 → latest', $job->versionLabel());
    }
}
