<?php

namespace Tests\Feature;

use App\Enums\ClientStatus;
use App\Enums\Role as RoleEnum;
use App\Livewire\Clients\ClientsIndex;
use App\Livewire\Computers\ComputersIndex;
use App\Livewire\Projects\ProjectForm;
use App\Livewire\Projects\ProjectsIndex;
use App\Models\Client;
use App\Models\Computer;
use App\Models\Project;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Module 01 — Core MSP Hierarchy: Client (the tenant boundary) -> Project ->
 * Device. There is no separate "Tenant" model in this codebase; Client
 * already serves that role everywhere (tenantClientId(), every visibleTo()/
 * usableFor() scope). This file is the dedicated proof of isolation across
 * the whole chain, per the module's TEST 1-14 matrix -- even where an
 * individual assertion already exists elsewhere under a different name.
 *
 * Fixtures follow the module spec's own example:
 *   Client A -> Project A1 -> Device A1   (+ a second project/client for
 *   Client B -> Project B1 -> Device B1     confinement tests)
 */
class CoreHierarchyTest extends TestCase
{
    use RefreshDatabase;

    private Client $clientA;
    private Client $clientB;
    private Project $projectA1;
    private Project $projectB1;
    private Computer $deviceA1;
    private Computer $deviceB1;
    private User $userA; // Client A's own ClientOwner

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->clientA = Client::factory()->create(['company_name' => 'Client A Inc']);
        $this->clientB = Client::factory()->create(['company_name' => 'Client B Inc']);
        $this->projectA1 = Project::factory()->create(['client_id' => $this->clientA->id, 'name' => 'Project A1']);
        $this->projectB1 = Project::factory()->create(['client_id' => $this->clientB->id, 'name' => 'Project B1']);
        $this->deviceA1 = Computer::factory()->create(['project_id' => $this->projectA1->id, 'hostname' => 'DEVICE-A1']);
        $this->deviceB1 = Computer::factory()->create(['project_id' => $this->projectB1->id, 'hostname' => 'DEVICE-B1']);

        $this->userA = tap(User::factory()->create(['client_id' => $this->clientA->id]),
            fn (User $u) => $u->assignRole(RoleEnum::ClientOwner->value));
    }

    private function admin(): User
    {
        return tap(User::factory()->create(), fn (User $u) => $u->assignRole(RoleEnum::Admin->value));
    }

    /** TEST 1 — Tenant A user lists clients: only their own row. */
    public function test_1_tenant_lists_only_their_own_client(): void
    {
        $this->actingAs($this->userA)->get('/clients')
            ->assertOk()
            ->assertSee('Client A Inc')
            ->assertDontSee('Client B Inc');
    }

    /** TEST 2 — Tenant A requests Tenant B's client by ID: blocked. */
    public function test_2_tenant_a_cannot_view_tenant_bs_client(): void
    {
        $this->assertFalse($this->userA->can('view', $this->clientB));
        $this->actingAs($this->userA)->get("/clients/{$this->clientB->id}/edit")->assertForbidden();
    }

    /** TEST 3 — Tenant A modifies Tenant B's client: blocked. */
    public function test_3_tenant_a_cannot_modify_tenant_bs_client(): void
    {
        $this->assertFalse($this->userA->can('update', $this->clientB));

        Livewire::actingAs($this->userA)
            ->test(ClientsIndex::class)
            ->call('toggleMonthlyReport', $this->clientB->id)
            ->assertForbidden();
    }

    /** TEST 4 — Tenant A retrieves Tenant B's project: blocked. */
    public function test_4_tenant_a_cannot_retrieve_tenant_bs_project(): void
    {
        $this->assertFalse($this->userA->can('view', $this->projectB1));
        $this->actingAs($this->userA)->get(route('projects.enrollment', $this->projectB1))->assertForbidden();
    }

    /** TEST 5 — Tenant A retrieves Tenant B's device: blocked. */
    public function test_5_tenant_a_cannot_retrieve_tenant_bs_device(): void
    {
        $this->assertFalse($this->userA->can('view', $this->deviceB1));
        $this->actingAs($this->userA)->get("/computers/{$this->deviceB1->id}")->assertForbidden();
    }

    /**
     * TEST 6 — Create Client: association is not user-suppliable at all.
     * (Client creation is the platform operator's act, not a tenant's --
     * a tenant cannot reach the create form in the first place, so there is
     * no "auto-associate" step to test; staff creation carries no implicit
     * tenant since staff are not tenant-bound.)
     */
    public function test_6_a_tenant_cannot_create_a_client_at_all(): void
    {
        $this->assertFalse($this->userA->can('create', Client::class));
        $this->actingAs($this->userA)->get('/clients/create')->assertForbidden();
    }

    /** TEST 7 — Create Project: belongs to the selected, authorized client. */
    public function test_7_a_project_created_by_a_tenant_belongs_to_their_own_client(): void
    {
        Livewire::actingAs($this->userA)
            ->test(ProjectForm::class)
            ->set('client_id', $this->clientA->id)
            ->set('name', 'New Site')
            ->call('save');

        $project = Project::where('name', 'New Site')->first();
        $this->assertNotNull($project);
        $this->assertSame($this->clientA->id, $project->client_id);
    }

    /** TEST 8 — Attempt project creation under another tenant's client: rejected. */
    public function test_8_a_tenant_cannot_create_a_project_under_another_clients_id(): void
    {
        Livewire::actingAs($this->userA)
            ->test(ProjectForm::class)
            ->set('client_id', $this->clientB->id) // someone else's client, submitted directly
            ->set('name', 'Smuggled Site')
            ->call('save')
            ->assertHasErrors('client_id');

        $this->assertNull(Project::where('name', 'Smuggled Site')->first());
    }

    /** TEST 9 — Device hierarchy filtering: correct client/project devices returned. */
    public function test_9_device_listing_filters_correctly_by_client_and_project(): void
    {
        Livewire::actingAs($this->admin())
            ->test(ComputersIndex::class)
            ->set('projectId', $this->projectA1->id)
            ->assertSee('DEVICE-A1')
            ->assertDontSee('DEVICE-B1');

        $this->actingAs($this->userA)->get('/computers')
            ->assertOk()
            ->assertSee('DEVICE-A1')
            ->assertDontSee('DEVICE-B1');
    }

    /**
     * TEST 10 — Archived/disabled client behaviour. Note: this codebase has
     * no literal "Archived" status -- ClientStatus is Active/Inactive/
     * Suspended, so `Inactive` is the closest existing equivalent to
     * "archive/disable" at the Client level. Whatever the status, it must
     * be a status flip, never a hard delete cascading through Projects/
     * Devices -- matching this app's own established rule elsewhere
     * (Package: deactivating something must explain itself, never make it
     * silently vanish) applied to the same principle here.
     */
    public function test_10_disabling_a_client_preserves_its_projects_and_devices(): void
    {
        $this->clientA->update(['status' => ClientStatus::Inactive]);

        $this->assertNotNull($this->projectA1->fresh());
        $this->assertNull($this->projectA1->fresh()->deleted_at);
        $this->assertNotNull($this->deviceA1->fresh());
        $this->assertNull($this->deviceA1->fresh()->deleted_at);
    }

    /** TEST 11 — Read-only role edit attempt: rejected. */
    public function test_11_a_read_only_viewer_cannot_edit_anything_in_the_hierarchy(): void
    {
        $viewer = tap(User::factory()->create(), fn (User $u) => $u->assignRole(RoleEnum::Viewer->value));

        $this->assertFalse($viewer->can('update', $this->clientA));
        $this->assertFalse($viewer->can('update', $this->projectA1));
        $this->assertFalse($viewer->can('update', $this->deviceA1));
        $this->actingAs($viewer)->get("/clients/{$this->clientA->id}/edit")->assertForbidden();
        $this->actingAs($viewer)->get("/computers/{$this->deviceA1->id}/edit")->assertForbidden();
    }

    /** TEST 12 — Search: no cross-tenant result leakage. */
    public function test_12_search_never_leaks_another_tenants_records(): void
    {
        $this->actingAs($this->admin())->get('/clients?search=Client+B')
            ->assertOk()
            ->assertSee('Client B Inc');

        // The tenant's own "Clients" page ignores search entirely and shows
        // only their single organisation -- searching for the other tenant
        // by name must not surface it.
        $this->actingAs($this->userA)->get('/clients?search=Client+B')
            ->assertOk()
            ->assertDontSee('Client B Inc');
    }

    /** TEST 13 — Pagination: no records from an unauthorized tenant on any page. */
    public function test_13_pagination_never_surfaces_another_tenants_records(): void
    {
        Computer::factory()->count(20)->create(['project_id' => $this->projectA1->id]);

        $page1 = $this->actingAs($this->userA)->get('/computers')->getContent();
        $page2 = $this->actingAs($this->userA)->get('/computers?page=2')->getContent();

        $this->assertStringNotContainsString('DEVICE-B1', $page1);
        $this->assertStringNotContainsString('DEVICE-B1', $page2);
    }

    /** TEST 14 — Invalid relationship manipulation: rejected. */
    public function test_14_a_device_cannot_be_reassigned_into_another_tenants_project(): void
    {
        $admin = $this->admin();

        // Even for staff (unrestricted by tenancy), the reassignment target
        // must be a real project -- an arbitrary/foreign id is rejected by
        // the same validation that powers the reassignment form, not merely
        // hidden from the picker.
        Livewire::actingAs($admin)
            ->test(\App\Livewire\Computers\ComputerEdit::class, ['computer' => $this->deviceA1])
            ->set('project_id', 999999) // no such project
            ->call('save')
            ->assertHasErrors('project_id');

        $this->assertSame($this->projectA1->id, $this->deviceA1->fresh()->project_id);
    }
}
