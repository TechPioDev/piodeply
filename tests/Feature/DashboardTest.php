<?php

namespace Tests\Feature;

use App\Enums\Role as RoleEnum;
use App\Livewire\Dashboard;
use App\Models\Client;
use App\Models\Computer;
use App\Models\ComputerSoftware;
use App\Models\DeploymentJob;
use App\Models\Package;
use App\Models\Project;
use App\Models\User;
use App\Services\PackageService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DashboardTest extends TestCase
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

    public function test_dashboard_requires_auth(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_tiles_reflect_fleet_and_job_state(): void
    {
        $client = Client::factory()->create(['company_name' => 'Tile Corp']);
        $project = Project::factory()->for($client)->create();
        $online = Computer::factory()->for($project)->online()->count(2)->create();
        Computer::factory()->for($project)->offline()->create();
        DeploymentJob::factory()->create(['computer_id' => $online[0]->id]);            // pending
        DeploymentJob::factory()->failed()->create(['computer_id' => $online[0]->id]);

        Livewire::actingAs($this->admin())
            ->test(Dashboard::class)
            ->assertViewHas('stats', fn ($stats) => $stats['online'] === 2
                && $stats['offline'] === 1
                && $stats['pending'] === 1
                && $stats['failed'] === 1
                && $stats['clients'] === Client::count()
                && $stats['projects'] === Project::count())
            ->assertSee('Computers online')
            ->assertSee('Failed jobs')
            ->assertSee('Tile Corp'); // fleet-by-client chart row
    }

    /** An old failed attempt a later retry superseded is history, not a currently failing machine. */
    public function test_failed_jobs_tile_is_folded_to_the_current_state_per_task(): void
    {
        $computer = Computer::factory()->create();
        $package = Package::factory()->create();

        DeploymentJob::factory()->failed()->create([
            'computer_id' => $computer->id, 'package_id' => $package->id, 'action' => 'install',
        ]);
        DeploymentJob::factory()->succeeded()->create([
            'computer_id' => $computer->id, 'package_id' => $package->id, 'action' => 'install',
        ]);
        // A different, still-failing task.
        DeploymentJob::factory()->failed()->create([
            'computer_id' => $computer->id, 'package_id' => Package::factory()->create()->id, 'action' => 'install',
        ]);

        Livewire::actingAs($this->admin())
            ->test(Dashboard::class)
            ->assertViewHas('stats', fn ($stats) => $stats['failed'] === 1);
    }

    public function test_outdated_software_compares_against_pinned_latest(): void
    {
        $package = Package::factory()->create(['winget_id' => 'Vendor.App']);
        app(PackageService::class)->addVersion($package, ['version' => '2.0.0']);

        $computer = Computer::factory()->create();
        ComputerSoftware::factory()->create([
            'computer_id' => $computer->id, 'name' => 'Vendor.App', 'version' => '1.0.0', 'source' => 'winget',
        ]);
        // Same version -> not outdated
        ComputerSoftware::factory()->create([
            'computer_id' => $computer->id, 'name' => 'Vendor.App', 'version' => '2.0.0', 'source' => 'winget',
        ]);

        Livewire::actingAs($this->admin())
            ->test(Dashboard::class)
            ->assertViewHas('stats', fn ($stats) => $stats['outdated'] === 1);
    }

    public function test_license_usage_counts_commercial_installs(): void
    {
        $commercial = Package::factory()->create(['winget_id' => 'Paid.App', 'license' => 'Commercial']);
        Package::factory()->create(['winget_id' => 'Free.App', 'license' => 'MIT']);
        $computer = Computer::factory()->create();
        ComputerSoftware::factory()->create(['computer_id' => $computer->id, 'name' => 'Paid.App', 'source' => 'winget']);
        ComputerSoftware::factory()->create(['computer_id' => $computer->id, 'name' => 'Free.App', 'source' => 'winget']);

        Livewire::actingAs($this->admin())
            ->test(Dashboard::class)
            ->assertViewHas('stats', fn ($stats) => $stats['licenses'] === 1);
    }

    public function test_deployment_series_covers_14_days_and_counts_statuses(): void
    {
        DeploymentJob::factory()->succeeded()->count(2)->create();
        DeploymentJob::factory()->failed()->create();

        Livewire::actingAs($this->admin())
            ->test(Dashboard::class)
            ->assertViewHas('series', function ($series) {
                $today = collect($series)->last();

                return count($series) === 14
                    && $today['succeeded'] === 2
                    && $today['failed'] === 1;
            });
    }

    public function test_dashboard_shows_recent_activity(): void
    {
        Client::factory()->create(['company_name' => 'Audit Trail Co']); // generates activity

        Livewire::actingAs($this->admin())
            ->test(Dashboard::class)
            ->assertSee('Recent activity')
            ->assertSee('created');
    }

    /* ─────────── device health summary ─────────── */

    /**
     * A never-reported machine is a different situation from one that
     * reported and scored badly — it gets its own "unknown" tier rather
     * than being counted as unhealthy on the strength of one low score.
     */
    public function test_device_health_tiers_bucket_the_fleet_by_score_and_reporting_state(): void
    {
        // Disk fields are set explicitly on every row: the factory's default
        // free-space range can itself cross healthScore()'s 10%/20% disk
        // thresholds, which would make these buckets flaky.
        $safeDisk = ['disk_total_bytes' => 500_000_000_000, 'disk_free_bytes' => 250_000_000_000]; // 50% free -> no deduction

        Computer::factory()->create($safeDisk + [
            'last_seen_at' => now(), 'agent_version' => Computer::latestAgentVersion(),
            'secure_boot' => true, 'tpm_enabled' => true,
        ]); // score 100 -> healthy

        $needsAttention = Computer::factory()->create($safeDisk + [
            'last_seen_at' => now(), 'agent_version' => '1.0.0', // -10 outdated agent
            'secure_boot' => true, 'tpm_enabled' => true,
        ]);
        DeploymentJob::factory()->failed()->create(['computer_id' => $needsAttention->id]); // -10 -> score 80, needs attention

        Computer::factory()->create($safeDisk + [
            'last_seen_at' => now()->subDays(3), // -25 offline
            'secure_boot' => false, 'tpm_enabled' => false, // -10 -10
        ]); // score 55 -> unhealthy

        Computer::factory()->neverSeen()->create(); // unknown

        Livewire::actingAs($this->admin())
            ->test(Dashboard::class)
            ->assertViewHas('deviceHealth', fn ($dh) => $dh['total'] === 4
                && $dh['tiers']['healthy'] === 1
                && $dh['tiers']['needs_attention'] === 1
                && $dh['tiers']['unhealthy'] === 1
                && $dh['tiers']['unknown'] === 1)
            ->assertSee('Device health summary');
    }

    public function test_device_health_hardware_mix_splits_virtual_from_physical(): void
    {
        Computer::factory()->create(['manufacturer' => 'VMware, Inc.', 'model' => 'VMware7,1']);
        Computer::factory()->count(2)->create(['manufacturer' => 'Dell Inc.', 'model' => 'OptiPlex 7010']);
        Computer::factory()->create(['manufacturer' => null, 'model' => null]);

        Livewire::actingAs($this->admin())
            ->test(Dashboard::class)
            ->assertViewHas('deviceHealth', fn ($dh) => $dh['hardware']['virtual'] === 1
                && $dh['hardware']['physical'] === 2
                && $dh['hardware']['unknown'] === 1)
            ->assertSee('Virtual machines')
            ->assertSee('Physical machines');
    }

    /** Every issue row must be a real, already-existing signal with a working drill-down link — never a fabricated capability. */
    public function test_device_health_issue_counts_match_the_fleet_and_link_to_the_filtered_list(): void
    {
        Computer::factory()->offline()->create(['hostname' => 'OFFLINE-PC']);
        Computer::factory()->online()->create(['hostname' => 'ONLINE-PC']);

        Livewire::actingAs($this->admin())
            ->test(Dashboard::class)
            ->assertViewHas('deviceHealth', function ($dh) {
                $offlineIssue = collect($dh['issues'])->firstWhere('key', 'offline');

                return $offlineIssue['count'] === 1
                    && $offlineIssue['route'] === 'computers.index'
                    && $offlineIssue['params'] === ['connectivity' => 'offline'];
            });

        // The link actually filters the list down to the offline machine.
        Livewire::actingAs($this->admin())
            ->test(\App\Livewire\Computers\ComputersIndex::class)
            ->set('connectivity', 'offline')
            ->assertSee('OFFLINE-PC')
            ->assertDontSee('ONLINE-PC');
    }

    public function test_device_health_does_not_claim_capabilities_pioDeploy_does_not_have(): void
    {
        Computer::factory()->create();

        Livewire::actingAs($this->admin())
            ->test(Dashboard::class)
            ->assertDontSee('NMS')
            ->assertDontSee('VMware hosts down')
            ->assertDontSee('Hyper-V hosts down')
            ->assertDontSee('antivirus')
            ->assertDontSee('vulnerabilities')
            ->assertDontSee('Lost mode');
    }
}
