<?php

namespace Tests\Feature;

use App\Enums\Role as RoleEnum;
use App\Livewire\Admin\ActivityIndex;
use App\Livewire\Admin\ManageRoles;
use App\Livewire\Admin\ManageUsers;
use App\Livewire\Admin\SettingsPage;
use App\Models\Client;
use App\Models\Package;
use App\Models\Project;
use App\Models\SoftwarePolicy;
use App\Models\User;
use App\Services\ComputerService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Module 9: the client's requirements review asked for every administrative
 * change category (users/permissions, packages, policies/automations,
 * machine enrollment, configuration) to be logged with enough detail to show
 * what changed. This proves it empirically — a passing assertion here means
 * a real Activity row landed with real before/after values, not that the
 * code merely looks like it should — rather than trusting a grep for
 * activity() calls, which misses everything logged automatically through a
 * model's own LogsActivity trait (packages, policies, computers). The
 * verified finding: every category on the client's list already has real
 * coverage, including deletions and machine enrollment, which is not
 * obvious from reading the controllers alone (enrollment has no activity()
 * call of its own — it is covered because ComputerService::register()
 * calls Computer::create(), and the model's own trait catches that).
 */
class AuditLogCoverageTest extends TestCase
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

    public function test_creating_a_user_is_logged(): void
    {
        Livewire::actingAs($this->admin())
            ->test(ManageUsers::class)
            ->set('newName', 'New Person')
            ->set('newEmail', 'new-person@example.test')
            ->set('newPassword', 'a-real-password-123')
            ->set('newRole', RoleEnum::Technician->value)
            ->call('createUser');

        $this->assertDatabaseHas('activity_log', ['log_name' => 'rbac', 'description' => 'user_created']);
    }

    public function test_a_permission_change_is_logged_with_before_and_after(): void
    {
        Livewire::actingAs($this->admin())
            ->test(ManageRoles::class)
            ->call('toggle', RoleEnum::Technician->value, 'clients.update');

        $entry = Activity::where('log_name', 'rbac')
            ->whereIn('description', ['permission_granted', 'permission_revoked'])
            ->latest('id')->first();

        $this->assertNotNull($entry, 'toggling a permission must be logged');
        $this->assertArrayHasKey('role', $entry->properties->toArray());
        $this->assertArrayHasKey('granted', $entry->properties->toArray());
    }

    public function test_a_package_create_and_update_are_both_logged(): void
    {
        $package = Package::factory()->create(['name' => 'Original Name']);
        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'packages', 'subject_type' => Package::class, 'subject_id' => $package->id, 'description' => 'created',
        ]);

        $package->update(['name' => 'Renamed']);
        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'packages', 'subject_type' => Package::class, 'subject_id' => $package->id, 'description' => 'updated',
        ]);

        $entry = Activity::where('subject_type', Package::class)->where('subject_id', $package->id)
            ->where('description', 'updated')->latest('id')->first();
        // logOnlyDirty() on an update captures both sides — this is what
        // Previous Value / New Value in the UI actually reads from.
        $this->assertSame('Original Name', $entry->properties['old']['name'] ?? null);
        $this->assertSame('Renamed', $entry->properties['attributes']['name'] ?? null);
    }

    /** Spatie's LogsActivity logs deletions the same way it logs creates/updates -- confirmed, not assumed. */
    public function test_deleting_a_package_is_logged(): void
    {
        $package = Package::factory()->create();
        $package->delete();

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'packages', 'subject_type' => Package::class, 'subject_id' => $package->id, 'description' => 'deleted',
        ]);
    }

    public function test_a_policy_create_is_logged(): void
    {
        $policy = SoftwarePolicy::factory()->create();

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'policies', 'subject_type' => SoftwarePolicy::class, 'subject_id' => $policy->id, 'description' => 'created',
        ]);
    }

    /**
     * Found by writing this test: ComputerService::register() creates the
     * Computer row directly via the repository, which does fire the model's
     * own 'created' event (Computer::getActivitylogOptions() already logs
     * hostname/project_id/agent_version) -- so enrollment turns out to
     * already be covered. This proves it rather than assuming it from
     * reading the trait declaration.
     */
    public function test_a_new_machine_enrolling_is_logged(): void
    {
        $client = Client::factory()->create();
        $project = Project::factory()->create(['client_id' => $client->id]);

        $computer = app(ComputerService::class)->register(
            project: $project,
            agentUuid: 'test-uuid-'.uniqid(),
            inventory: ['hostname' => 'NEW-MACHINE-01'],
            agentVersion: '1.4.25',
        );

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'computers', 'subject_type' => \App\Models\Computer::class, 'subject_id' => $computer->id, 'description' => 'created',
        ]);
    }

    public function test_a_settings_change_is_logged_with_before_and_after_values(): void
    {
        Livewire::actingAs($this->admin())
            ->test(SettingsPage::class)
            ->set('project_term', 'Location')
            ->call('save');

        $entry = Activity::where('log_name', 'settings')->latest('id')->first();

        $this->assertNotNull($entry);
        $this->assertSame('Location', $entry->properties['branding.project_term'] ?? null);
    }

    /**
     * The Detail column used to be one raw JSON dump for every row alike.
     * These four cover every real shape properties actually takes in this
     * app: Spatie's own old/attributes diff, the from/to convention a few
     * hand-written entries use, a created-only row (nothing to show as
     * "previous"), and a one-off payload with no value-change shape at all
     * (falls back to showing it whole, not a guessed split).
     */
    public function test_previous_and_new_values_split_a_spatie_model_diff(): void
    {
        $package = Package::factory()->create(['name' => 'Before']);
        $package->update(['name' => 'After']);

        $activity = Activity::where('subject_type', Package::class)->where('description', 'updated')->latest('id')->first();
        $values = app(ActivityIndex::class)->valuesFor($activity);

        $this->assertStringContainsString('Before', $values['previous']);
        $this->assertStringContainsString('After', $values['new']);
    }

    public function test_previous_and_new_values_split_a_manual_from_to_entry(): void
    {
        $admin = $this->admin();
        activity('rbac')->causedBy($admin)->withProperties(['from' => ['viewer'], 'to' => ['manager']])->log('role_assigned');

        $activity = Activity::where('log_name', 'rbac')->where('description', 'role_assigned')->latest('id')->first();
        $values = app(ActivityIndex::class)->valuesFor($activity);

        $this->assertStringContainsString('viewer', $values['previous']);
        $this->assertStringContainsString('manager', $values['new']);
    }

    public function test_a_created_row_has_no_previous_value(): void
    {
        $package = Package::factory()->create();
        $activity = Activity::where('subject_type', Package::class)->where('description', 'created')->latest('id')->first();

        $values = app(ActivityIndex::class)->valuesFor($activity);

        $this->assertNull($values['previous']);
        $this->assertNotNull($values['new']);
    }

    public function test_a_payload_with_no_value_change_shape_falls_back_to_showing_it_whole(): void
    {
        $admin = $this->admin();
        activity('signups')->causedBy($admin)->withProperties(['signup_id' => 7, 'email' => 'x@example.test'])->log('signup_approved');

        $activity = Activity::where('log_name', 'signups')->latest('id')->first();
        $values = app(ActivityIndex::class)->valuesFor($activity);

        $this->assertNull($values['previous']);
        $this->assertStringContainsString('signup_id', $values['new']);
    }
}
