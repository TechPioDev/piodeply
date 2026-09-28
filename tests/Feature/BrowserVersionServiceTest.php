<?php

namespace Tests\Feature;

use App\Enums\Browser;
use App\Enums\JobStatus;
use App\Models\Client;
use App\Models\Computer;
use App\Models\Project;
use App\Services\BrowserVersionService;
use App\Services\ComputerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Edge cannot be deployed (PackageMode::OsManaged) and winget's own listing
 * can lag what is really installed — observed directly in production, one
 * machine reporting two different Firefox versions from winget vs. registry
 * in the same inventory, winget behind. This is the only place that answers
 * "is it actually current" for that class of software, and it does so by
 * comparing machines to each other, never to a release feed this platform
 * cannot verify.
 */
class BrowserVersionServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    }

    private function service(): BrowserVersionService
    {
        return app(BrowserVersionService::class);
    }

    private function computer(?Client $client = null): Computer
    {
        $client ??= Client::factory()->create();
        $project = Project::factory()->create(['client_id' => $client->id]);

        return Computer::factory()->create(['project_id' => $project->id]);
    }

    private function report(Computer $computer, array $items): void
    {
        app(ComputerService::class)->replaceSoftwareInventory($computer, $items);
    }

    // ── Recording ─────────────────────────────────────────────────────

    public function test_a_registry_reported_edge_is_recorded(): void
    {
        $computer = $this->computer();
        $this->report($computer, [
            ['name' => 'Microsoft Edge', 'version' => '150.0.4078.83', 'source' => 'registry'],
        ]);

        $this->assertDatabaseHas('browser_version_observations', [
            'computer_id' => $computer->id, 'browser' => 'edge', 'version' => '150.0.4078.83',
        ]);
    }

    /**
     * The exact production finding: winget's OWN listing must never be
     * trusted for "what version is really installed" — it can lag a
     * self-updating browser. Only registry/msi-sourced rows are recorded.
     */
    public function test_a_winget_sourced_row_is_not_trusted_for_the_installed_version(): void
    {
        $computer = $this->computer();
        $this->report($computer, [
            ['name' => 'Mozilla Firefox (x64 en-US)', 'version' => '153.0.1', 'source' => 'registry'],
            ['name' => 'Mozilla.Firefox', 'version' => '152.0.6', 'source' => 'winget'], // stale, per real data
        ]);

        $this->assertDatabaseHas('browser_version_observations', [
            'computer_id' => $computer->id, 'browser' => 'firefox', 'version' => '153.0.1',
        ]);
        $this->assertDatabaseMissing('browser_version_observations', [
            'computer_id' => $computer->id, 'browser' => 'firefox', 'version' => '152.0.6',
        ]);
    }

    public function test_chrome_is_recognised_from_its_msi_sourced_name(): void
    {
        $computer = $this->computer();
        $this->report($computer, [
            ['name' => 'Google Chrome', 'version' => '150.0.7871.130', 'source' => 'msi'],
        ]);

        $this->assertDatabaseHas('browser_version_observations', ['computer_id' => $computer->id, 'browser' => 'chrome']);
    }

    public function test_unrelated_software_is_not_mistaken_for_a_browser(): void
    {
        $computer = $this->computer();
        $this->report($computer, [
            ['name' => 'RedGear COSMO 7.1', 'version' => '1.00.0019', 'source' => 'registry'],
            ['name' => '7-Zip', 'version' => '23.01', 'source' => 'registry'],
        ]);

        $this->assertSame(0, \App\Models\BrowserVersionObservation::count());
    }

    /**
     * The whole reason this table exists: a version that has been current
     * for weeks must show a first_seen_at from weeks ago, not from the most
     * recent report — computer_software cannot do this because it is
     * wholesale replaced on every report, always stamped "now".
     */
    public function test_an_unchanged_version_keeps_its_original_first_seen_at(): void
    {
        $computer = $this->computer();

        $this->travelTo(now()->subDays(20));
        $this->report($computer, [['name' => 'Microsoft Edge', 'version' => '150.0.1', 'source' => 'registry']]);
        $firstSeen = \App\Models\BrowserVersionObservation::first()->first_seen_at;

        $this->travelTo(now()->addDays(20)); // 20 days later, same version reported again
        $this->report($computer, [['name' => 'Microsoft Edge', 'version' => '150.0.1', 'source' => 'registry']]);

        $row = \App\Models\BrowserVersionObservation::first();
        $this->assertTrue($row->first_seen_at->eq($firstSeen), 'first_seen_at must not move');
        $this->assertTrue($row->last_seen_at->gt($firstSeen), 'last_seen_at must have advanced');
        $this->assertSame(1, \App\Models\BrowserVersionObservation::count(), 'still one row, not a duplicate');

        $this->travelBack();
    }

    public function test_a_version_change_creates_a_new_row_and_leaves_the_old_one_as_history(): void
    {
        $computer = $this->computer();
        $this->report($computer, [['name' => 'Microsoft Edge', 'version' => '150.0.1', 'source' => 'registry']]);
        $this->report($computer, [['name' => 'Microsoft Edge', 'version' => '150.0.2', 'source' => 'registry']]);

        $this->assertSame(2, \App\Models\BrowserVersionObservation::count());
    }

    // ── Fleet-relative comparison ─────────────────────────────────────

    public function test_fleet_latest_is_the_newest_version_actually_seen(): void
    {
        $client = Client::factory()->create();
        $a = $this->computer($client);
        $b = $this->computer($client);
        $this->report($a, [['name' => 'Microsoft Edge', 'version' => '150.0.1', 'source' => 'registry']]);
        $this->report($b, [['name' => 'Microsoft Edge', 'version' => '150.0.10', 'source' => 'registry']]);

        // version_compare, not string sort: '150.0.10' > '150.0.9' but would
        // sort BEFORE it as plain strings.
        $this->report($a, [['name' => 'Microsoft Edge', 'version' => '150.0.9', 'source' => 'registry']]);

        $latest = $this->service()->fleetLatestForClient($client->id);

        $this->assertSame('150.0.10', $latest['edge']);
    }

    /**
     * The core safety property: a health score must never compare one MSP
     * customer's fleet against a different customer's.
     */
    public function test_fleet_latest_never_crosses_clients(): void
    {
        $mine = Client::factory()->create();
        $theirs = Client::factory()->create();
        $this->report($this->computer($mine), [['name' => 'Microsoft Edge', 'version' => '150.0.1', 'source' => 'registry']]);
        $this->report($this->computer($theirs), [['name' => 'Microsoft Edge', 'version' => '999.0.0', 'source' => 'registry']]);

        $latest = $this->service()->fleetLatestForClient($mine->id);

        $this->assertSame('150.0.1', $latest['edge']);
    }

    public function test_fleet_latest_by_client_groups_every_client_in_one_query(): void
    {
        $clientA = Client::factory()->create();
        $clientB = Client::factory()->create();
        $this->report($this->computer($clientA), [['name' => 'Microsoft Edge', 'version' => '1.0', 'source' => 'registry']]);
        $this->report($this->computer($clientB), [['name' => 'Microsoft Edge', 'version' => '2.0', 'source' => 'registry']]);

        $byClient = $this->service()->fleetLatestByClient();

        $this->assertSame('1.0', $byClient[$clientA->id]['edge']);
        $this->assertSame('2.0', $byClient[$clientB->id]['edge']);
    }

    // ── Stuck detection ───────────────────────────────────────────────

    public function test_behind_but_recent_is_not_stuck(): void
    {
        $client = Client::factory()->create();
        $leader = $this->computer($client);
        $laggard = $this->computer($client);
        $this->report($leader, [['name' => 'Microsoft Edge', 'version' => '150.0.2', 'source' => 'registry']]);
        $this->report($laggard, [['name' => 'Microsoft Edge', 'version' => '150.0.1', 'source' => 'registry']]);

        $latest = $this->service()->fleetLatestForClient($client->id);
        $notes = $this->service()->stuckNotes($laggard, $latest);

        $this->assertSame([], $notes, 'a few days behind is normal, not stuck');
    }

    public function test_behind_and_motionless_past_the_threshold_is_stuck(): void
    {
        $client = Client::factory()->create();
        $laggard = $this->computer($client);

        $this->travelTo(now()->subDays(BrowserVersionService::STUCK_AFTER_DAYS + 5));
        $this->report($laggard, [['name' => 'Microsoft Edge', 'version' => '150.0.1', 'source' => 'registry']]);
        $this->travelBack();

        $leader = $this->computer($client);
        $this->report($leader, [['name' => 'Microsoft Edge', 'version' => '150.0.9', 'source' => 'registry']]);

        $latest = $this->service()->fleetLatestForClient($client->id);
        $notes = $this->service()->stuckNotes($laggard, $latest);

        $this->assertNotEmpty($notes);
        $this->assertStringContainsString('Edge', $notes[0]);
        $this->assertStringContainsString('150.0.1', $notes[0]);
    }

    /**
     * The machine that IS the fleet's newest can sit on its version for
     * months — that is what "current" looks like. It must never be flagged.
     */
    public function test_the_machine_that_is_current_is_never_flagged_no_matter_how_long(): void
    {
        $client = Client::factory()->create();
        $onlyMachine = $this->computer($client);

        $this->travelTo(now()->subDays(200));
        $this->report($onlyMachine, [['name' => 'Microsoft Edge', 'version' => '150.0.1', 'source' => 'registry']]);
        $this->travelBack();

        $latest = $this->service()->fleetLatestForClient($client->id);
        $notes = $this->service()->stuckNotes($onlyMachine, $latest);

        $this->assertSame([], $notes);
    }

    // ── Health score integration ──────────────────────────────────────

    public function test_a_stuck_browser_costs_health_points_with_a_readable_note(): void
    {
        $client = Client::factory()->create();
        $laggard = $this->computer($client);

        $this->travelTo(now()->subDays(BrowserVersionService::STUCK_AFTER_DAYS + 1));
        $this->report($laggard, [['name' => 'Microsoft Edge', 'version' => '1.0', 'source' => 'registry']]);
        $this->travelBack();

        $leader = $this->computer($client);
        $this->report($leader, [['name' => 'Microsoft Edge', 'version' => '9.0', 'source' => 'registry']]);

        $health = $laggard->fresh()->healthScore();

        $this->assertLessThan(100, $health['score']);
        $this->assertStringContainsString('stuck', implode(' ', $health['notes']));
    }

    public function test_a_healthy_machine_with_no_browsers_reported_is_unaffected(): void
    {
        // agent_version and disk explicit: the factory defaults are an old
        // agent build and a random free-space percentage (both exist to
        // test their own deductions elsewhere) — either can cost points on
        // its own and has nothing to do with browsers, and the random disk
        // figure made this assertion flaky (it occasionally crossed the
        // 10%/20% free thresholds).
        $computer = $this->computer();
        $computer->forceFill([
            'agent_version'    => \App\Services\EnrollmentScriptService::CURRENT_AGENT_VERSION,
            'disk_total_bytes' => 500_000_000_000,
            'disk_free_bytes'  => 250_000_000_000,
        ])->save();

        $this->assertSame(100, $computer->fresh()->healthScore()['score']);
    }

    /**
     * The batch-safe path every fleet-wide caller (dashboard average, the
     * report table, the PDF export) must use: passing a precomputed
     * fleetLatestByClient() slice must produce the identical score to
     * letting healthScore() resolve it alone, with zero extra queries.
     */
    public function test_a_precomputed_fleet_latest_matches_the_self_resolved_result(): void
    {
        $client = Client::factory()->create();
        $laggard = $this->computer($client);
        $this->travelTo(now()->subDays(BrowserVersionService::STUCK_AFTER_DAYS + 1));
        $this->report($laggard, [['name' => 'Microsoft Edge', 'version' => '1.0', 'source' => 'registry']]);
        $this->travelBack();
        $leader = $this->computer($client);
        $this->report($leader, [['name' => 'Microsoft Edge', 'version' => '9.0', 'source' => 'registry']]);

        $laggard = $laggard->fresh();
        $selfResolved = $laggard->healthScore();

        // BOTH computers, as a real caller always passes: the dashboard and
        // report pages preload for every row on screen, never one machine
        // at a time — that is the whole point of the parameter.
        $precomputed = app(BrowserVersionService::class)->fleetLatestByClient([$laggard->id, $leader->id]);
        $batchResolved = $laggard->healthScore($precomputed[$client->id] ?? null);

        $this->assertSame($selfResolved['score'], $batchResolved['score']);
        $this->assertLessThan(100, $batchResolved['score'], 'sanity: the laggard really is being marked down');
    }

    // ── Computer-show summary ─────────────────────────────────────────

    public function test_summary_for_reports_behind_and_fleet_latest_per_browser(): void
    {
        $client = Client::factory()->create();
        $mine = $this->computer($client);
        $this->report($mine, [['name' => 'Microsoft Edge', 'version' => '1.0', 'source' => 'registry']]);
        $this->report($this->computer($client), [['name' => 'Microsoft Edge', 'version' => '2.0', 'source' => 'registry']]);

        $latest = $this->service()->fleetLatestForClient($client->id);
        $summary = $this->service()->summaryFor($mine->fresh(), $latest);

        $this->assertCount(1, $summary);
        $this->assertSame(Browser::Edge, $summary[0]['browser']);
        $this->assertSame('1.0', $summary[0]['version']);
        $this->assertSame('2.0', $summary[0]['fleet_latest']);
        $this->assertTrue($summary[0]['behind']);
    }

    public function test_the_computer_page_renders_the_browsers_card(): void
    {
        $client = Client::factory()->create();
        $computer = $this->computer($client);
        $this->report($computer, [['name' => 'Microsoft Edge', 'version' => '150.0.1', 'source' => 'registry']]);

        $admin = tap(\App\Models\User::factory()->create(), fn ($u) => $u->assignRole(\App\Enums\Role::Admin->value));

        $this->actingAs($admin)
            ->get(route('computers.show', $computer->fresh()))
            ->assertOk()
            ->assertSee('Browsers')
            ->assertSee('150.0.1');
    }
}
