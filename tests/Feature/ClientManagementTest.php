<?php

namespace Tests\Feature;

use App\Enums\ClientStatus;
use App\Enums\Role as RoleEnum;
use App\Livewire\Clients\ClientForm;
use App\Livewire\Clients\ClientsIndex;
use App\Models\Client;
use App\Models\ClientContact;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class ClientManagementTest extends TestCase
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

    private function technician(): User
    {
        return tap(User::factory()->create(), fn (User $u) => $u->assignRole(RoleEnum::Technician->value));
    }

    public function test_a_tenant_sees_only_their_own_organisation_and_cannot_create_clients(): void
    {
        $mine = \App\Models\Client::factory()->create(['company_name' => 'Koms Global Technologies']);
        \App\Models\Client::factory()->create(['company_name' => 'Someone Else Ltd']);

        $owner = tap(\App\Models\User::factory()->create(['client_id' => $mine->id]),
            fn ($u) => $u->assignRole(\App\Enums\Role::ClientOwner->value));

        $this->actingAs($owner)->get('/clients')
            ->assertOk()
            ->assertSee('Koms Global Technologies')
            ->assertDontSee('Someone Else Ltd')
            // Creating clients is the platform operator's act; a customer's
            // "Clients" page is their own organisation, not a directory.
            ->assertDontSee('New Client');

        $this->assertFalse($owner->can('create', \App\Models\Client::class));
        $this->actingAs($owner)->get('/clients/create')->assertForbidden();
    }

    public function test_clients_pages_are_permission_gated(): void
    {
        $client = Client::factory()->create();

        $this->get('/clients')->assertRedirect('/login');

        $clientRoleUser = tap(User::factory()->create(), fn (User $u) => $u->assignRole(RoleEnum::Client->value));
        $this->actingAs($clientRoleUser)->get('/clients')->assertForbidden();

        $technician = $this->technician(); // clients.view but not clients.create
        $this->actingAs($technician)->get('/clients')->assertOk();
        $this->actingAs($technician)->get('/clients/create')->assertForbidden();

        $this->actingAs($this->admin())->get('/clients')->assertOk();
        $this->actingAs($this->admin())->get("/clients/{$client->id}/edit")->assertOk();
    }

    public function test_clients_menu_item_appears_for_permitted_users(): void
    {
        $this->actingAs($this->admin())->get('/dashboard')->assertSee('Clients');
    }

    public function test_admin_can_create_client_with_contacts_and_logo(): void
    {
        Storage::fake('public');

        Livewire::actingAs($this->admin())
            ->test(ClientForm::class)
            ->set('company_name', 'Acme Corp')
            ->set('email', 'it@acme.test')
            ->set('phone', '+1 555 0100')
            ->set('timezone', 'America/New_York')
            ->set('status', 'active')
            ->set('billing_email', 'billing@acme.test')
            ->set('logo', UploadedFile::fake()->image('logo.png', 200, 200))
            ->call('addContact')
            ->set('contacts.0.name', 'Jane Doe')
            ->set('contacts.0.email', 'jane@acme.test')
            ->set('contacts.0.is_primary', true)
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('clients.index'));

        $client = Client::where('email', 'it@acme.test')->first();
        $this->assertNotNull($client);
        $this->assertSame('Acme Corp', $client->company_name);
        $this->assertSame(ClientStatus::Active, $client->status);
        $this->assertCount(1, $client->contacts);
        $this->assertTrue($client->contacts->first()->is_primary);
        $this->assertNotNull($client->logo_path);
        Storage::disk('public')->assertExists($client->logo_path);
    }

    public function test_client_creation_is_activity_logged(): void
    {
        $client = Client::factory()->create(['company_name' => 'Logged Co']);

        $this->assertDatabaseHas('activity_log', [
            'log_name'     => 'clients',
            'subject_type' => Client::class,
            'subject_id'   => $client->id,
            'description'  => 'created',
        ]);
    }

    public function test_validation_rejects_bad_input(): void
    {
        Livewire::actingAs($this->admin())
            ->test(ClientForm::class)
            ->set('company_name', '')
            ->set('email', 'not-an-email')
            ->set('timezone', 'Mars/Olympus')
            ->call('save')
            ->assertHasErrors(['company_name', 'email', 'timezone']);
    }

    public function test_duplicate_email_is_rejected(): void
    {
        Client::factory()->create(['email' => 'dup@acme.test']);

        Livewire::actingAs($this->admin())
            ->test(ClientForm::class)
            ->set('company_name', 'Other Co')
            ->set('email', 'dup@acme.test')
            ->call('save')
            ->assertHasErrors(['email']);
    }

    public function test_admin_can_update_client_and_contacts_are_replaced(): void
    {
        $client = Client::factory()->create();
        ClientContact::factory()->count(2)->for($client)->create();

        Livewire::actingAs($this->admin())
            ->test(ClientForm::class, ['client' => $client])
            ->set('company_name', 'Renamed Co')
            ->call('addContact')
            ->set('contacts.2.name', 'New Contact')
            ->call('save')
            ->assertHasNoErrors();

        $client->refresh();
        $this->assertSame('Renamed Co', $client->company_name);
        $this->assertCount(3, $client->contacts);
        $this->assertSame(1, $client->contacts->where('is_primary', true)->count());
    }

    public function test_soft_delete_and_restore(): void
    {
        $client = Client::factory()->create();

        Livewire::actingAs($this->admin())
            ->test(ClientsIndex::class)
            ->call('delete', $client->id);

        $this->assertSoftDeleted('clients', ['id' => $client->id]);

        Livewire::actingAs($this->admin())
            ->test(ClientsIndex::class)
            ->set('showTrashed', true)
            ->call('restore', $client->id);

        $this->assertNull($client->fresh()->deleted_at);
    }

    public function test_technician_cannot_delete_clients(): void
    {
        $client = Client::factory()->create();

        Livewire::actingAs($this->technician())
            ->test(ClientsIndex::class)
            ->call('delete', $client->id)
            ->assertForbidden();

        $this->assertNull($client->fresh()->deleted_at);
    }

    public function test_search_and_status_filter(): void
    {
        Client::factory()->create(['company_name' => 'Findable Widgets', 'city' => 'Berlin']);
        Client::factory()->suspended()->create(['company_name' => 'Suspended Co']);

        Livewire::actingAs($this->admin())
            ->test(ClientsIndex::class)
            ->set('search', 'Findable')
            ->assertSee('Findable Widgets')
            ->assertDontSee('Suspended Co')
            ->set('search', '')
            ->set('status', 'suspended')
            ->assertSee('Suspended Co')
            ->assertDontSee('Findable Widgets');
    }

    public function test_export_produces_csv_with_clients(): void
    {
        Client::factory()->create(['company_name' => 'ExportMe Ltd', 'email' => 'x@export.test']);

        $component = Livewire::actingAs($this->admin())->test(ClientsIndex::class)->call('export');

        $response = $component->effects['download'] ?? null;
        $this->assertNotNull($response, 'export should trigger a download');
    }

    public function test_export_csv_content(): void
    {
        Client::factory()->create(['company_name' => 'ExportMe Ltd', 'email' => 'x@export.test']);

        $csv = app(\App\Services\ClientService::class)->exportCsv();

        $this->assertStringContainsString('company_name,email', $csv);
        $this->assertStringContainsString('ExportMe Ltd', $csv);
        $this->assertStringContainsString('x@export.test', $csv);
    }

    public function test_import_creates_and_updates_and_skips(): void
    {
        Client::factory()->create(['email' => 'existing@import.test', 'company_name' => 'Old Name']);

        $csv = implode("\n", [
            'company_name,email,phone,status',
            'New Co,new@import.test,+1 555 0101,active',
            'Updated Co,existing@import.test,,suspended',
            'Bad Row,not-an-email,,active',
        ]);

        $result = app(\App\Services\ClientService::class)->importCsv($csv);

        $this->assertSame(1, $result['imported']);
        $this->assertSame(1, $result['updated']);
        $this->assertCount(1, $result['skipped']);
        $this->assertDatabaseHas('clients', ['email' => 'new@import.test', 'company_name' => 'New Co']);
        $this->assertDatabaseHas('clients', ['email' => 'existing@import.test', 'company_name' => 'Updated Co', 'status' => 'suspended']);
    }
}
