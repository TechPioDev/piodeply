<?php

namespace Tests\Feature;

use App\Enums\JobAction;
use App\Enums\Role as RoleEnum;
use App\Livewire\Packages\PackageForm;
use App\Models\Client;
use App\Models\Computer;
use App\Models\Package;
use App\Models\Project;
use App\Models\User;
use App\Services\DeploymentService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The multi-tenant package contract: a client's private package is theirs
 * alone — invisible to other tenants, and undeployable to anyone else's
 * machines even by the Super Admin. Staff see everything; reuse is what
 * the guard forbids, and it reads ownership, not roles.
 */
class PackageTenancyTest extends TestCase
{
    use RefreshDatabase;

    private Client $clientA;

    private Client $clientB;

    private Package $privateA;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->clientA = Client::factory()->create(['company_name' => 'Alpha MSP']);
        $this->clientB = Client::factory()->create(['company_name' => 'Beta MSP']);
        $this->privateA = Package::factory()->create([
            'client_id' => $this->clientA->id,
            'name'      => 'Alpha In-House Tool',
        ]);
    }

    private function ownerOf(Client $client): User
    {
        return tap(User::factory()->create(['client_id' => $client->id]),
            fn (User $u) => $u->assignRole(RoleEnum::ClientOwner->value));
    }

    public function test_a_private_package_never_deploys_to_another_clients_machine(): void
    {
        $foreign = Computer::factory()->create([
            'project_id' => Project::factory()->create(['client_id' => $this->clientB->id])->id,
        ]);

        // Ownership, not role: even an unrestricted caller (Super Admin
        // path — the service has no user at all here) is refused.
        try {
            app(DeploymentService::class)->queue($foreign, $this->privateA, JobAction::Install);
            $this->fail('the cross-client deploy must be refused');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('Alpha MSP', $e->getMessage());
        }

        $this->assertSame(0, \App\Models\DeploymentJob::count());
    }

    public function test_the_package_deploys_fine_to_its_own_clients_machine(): void
    {
        $own = Computer::factory()->create([
            'project_id' => Project::factory()->create(['client_id' => $this->clientA->id])->id,
        ]);

        app(DeploymentService::class)->queue($own, $this->privateA, JobAction::Install);

        $this->assertSame(1, \App\Models\DeploymentJob::count());
    }

    public function test_other_tenants_cannot_even_see_a_private_package(): void
    {
        $ownerB = $this->ownerOf($this->clientB);

        $this->actingAs($ownerB)->get(route('packages.show', $this->privateA))->assertForbidden();

        // And it is absent from their visible set entirely.
        $visible = Package::visibleTo($ownerB)->pluck('id');
        $this->assertFalse($visible->contains($this->privateA->id));
    }

    public function test_staff_see_private_packages_but_tenants_own_theirs(): void
    {
        $admin = tap(User::factory()->create(), fn (User $u) => $u->assignRole(RoleEnum::Admin->value));

        $this->actingAs($admin)->get(route('packages.show', $this->privateA))->assertOk();
        $this->assertTrue(Package::visibleTo($admin)->pluck('id')->contains($this->privateA->id));
    }

    /** The summary cards are a headline number -- exactly the kind of figure that leaks a fleet size if it forgets tenancy. */
    public function test_the_summary_cards_never_count_another_tenants_private_packages(): void
    {
        Package::factory()->create(['client_id' => $this->clientB->id, 'name' => 'Beta In-House Tool']);
        $sharedCount = Package::whereNull('client_id')->count();

        $ownerA = $this->ownerOf($this->clientA);

        Livewire::actingAs($ownerA)
            ->test(\App\Livewire\Packages\PackagesIndex::class)
            // Sees the shared catalogue plus exactly their own one private
            // package -- never Beta's, which a naive "total" could leak.
            ->assertViewHas('stats', fn (array $s) => $s['total'] === $sharedCount + 1)
            ->assertDontSee('Beta In-House Tool');
    }

    /**
     * A tenant used to be able to publish a package privately to their own
     * client (packages.manage covered create too). That ability is
     * withdrawn — the MSP only ships tested software, so a new package now
     * always comes from staff building it, optionally to fulfil a request a
     * tenant filed (see PackageRequestTest for that full flow). Not even a
     * private-to-themselves package is still reachable directly.
     */
    public function test_a_tenant_can_no_longer_create_any_package_directly(): void
    {
        $owner = $this->ownerOf($this->clientB);

        Livewire::actingAs($owner)->test(PackageForm::class)->assertForbidden();

        $this->assertFalse($owner->can('create', Package::class));
    }

    public function test_a_tenant_cannot_edit_the_catalogue_or_another_tenants_package(): void
    {
        $ownerB = $this->ownerOf($this->clientB);
        $catalogue = Package::factory()->create(['client_id' => null]);

        $this->assertFalse($ownerB->can('update', $catalogue));
        $this->assertFalse($ownerB->can('update', $this->privateA));
        $this->assertFalse($ownerB->can('delete', $this->privateA));

        // Their own: full control.
        $own = Package::factory()->create(['client_id' => $this->clientB->id]);
        $this->assertTrue($ownerB->can('update', $own));
        $this->assertTrue($ownerB->can('delete', $own));
    }
}
