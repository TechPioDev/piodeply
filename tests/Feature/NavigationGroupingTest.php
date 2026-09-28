<?php

namespace Tests\Feature;

use App\Enums\JobAction;
use App\Enums\Role as RoleEnum;
use App\Models\Client;
use App\Models\ClientRole;
use App\Models\Computer;
use App\Models\Package;
use App\Models\Project;
use App\Models\User;
use App\Services\DeploymentService;
use App\Services\NavigationService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NavigationGroupingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function userWithRole(RoleEnum $role): User
    {
        return tap(User::factory()->create(), fn (User $u) => $u->assignRole($role->value));
    }

    private function nav(): NavigationService
    {
        return app(NavigationService::class);
    }

    /** @return array<string, list<string>> */
    private function labelsByGroup(User $user): array
    {
        return collect($this->nav()->groups($user))
            ->mapWithKeys(fn (array $g) => [$g['label'] ?? '' => array_column($g['items'], 'label')])
            ->all();
    }

    public function test_an_admin_sees_every_section_in_order(): void
    {
        $groups = $this->nav()->groups($this->userWithRole(RoleEnum::Admin));

        $this->assertSame(
            [NavigationService::DASHBOARD, NavigationService::ASSETS, NavigationService::SOFTWARE, NavigationService::MANAGEMENT, NavigationService::ADMIN, NavigationService::REPORTS, NavigationService::BILLING],
            array_column($groups, 'label')
        );
    }

    public function test_items_land_in_the_section_they_belong_to(): void
    {
        $byGroup = $this->labelsByGroup($this->userWithRole(RoleEnum::Admin));

        $this->assertSame(['Dashboard', 'Needs attention'], $byGroup[NavigationService::DASHBOARD]);
        $this->assertSame(['Clients', project_terms(), 'Computers', 'Device Groups'], $byGroup[NavigationService::ASSETS]);
        $this->assertSame(['Packages', 'Deployments', 'Licenses'], $byGroup[NavigationService::SOFTWARE]);
        $this->assertSame([policy_terms(), browser_policy_terms()], $byGroup[NavigationService::MANAGEMENT]);
        $this->assertSame(['Reports', 'Audit Logs'], $byGroup[NavigationService::REPORTS]);
    }

    /** An empty section heading would be worse than no grouping at all. */
    public function test_a_section_the_user_cannot_see_disappears_entirely(): void
    {
        $labels = array_column($this->nav()->groups($this->userWithRole(RoleEnum::Viewer)), 'label');

        // A viewer manages nothing, so Administration should not be a heading
        // hanging over an empty list.
        $this->assertNotContains(NavigationService::ADMIN, $labels);
    }

    public function test_grouping_does_not_smuggle_past_permissions(): void
    {
        $viewer = $this->userWithRole(RoleEnum::Viewer);

        $grouped = collect($this->nav()->groups($viewer))->flatMap(fn (array $g) => array_column($g['items'], 'label'))->all();
        $flat = array_column($this->nav()->items($viewer), 'label');

        // Same set either way — groups() is a view of items(), not a bypass.
        $this->assertSame($flat, $grouped);
        $this->assertNotContains('Roles', $grouped);
    }

    public function test_the_sidebar_renders_the_section_headings(): void
    {
        $this->actingAs($this->userWithRole(RoleEnum::Admin))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Assets')
            ->assertSee('Software')
            ->assertSee('Administration');
    }

    /** A collapsed sidebar must still show you where you are. */
    public function test_the_section_holding_the_current_page_opens_itself(): void
    {
        $this->actingAs($this->userWithRole(RoleEnum::Admin))
            ->get(route('computers.index'))
            ->assertOk()
            ->assertSee('data-nav-section="assets" data-holds-current-page="true"', false)
            ->assertSee('data-nav-section="software" data-holds-current-page="false"', false);
    }

    public function test_sections_start_collapsed_elsewhere(): void
    {
        $response = $this->actingAs($this->userWithRole(RoleEnum::Admin))
            ->get(route('dashboard'))
            ->assertOk();

        // Dashboard is itself a section now (it holds Needs attention too),
        // so it is the one expected to hold the current page here.
        $response->assertSee('data-nav-section="dashboard" data-holds-current-page="true"', false);

        foreach (['assets', 'software', 'management', 'administration', 'reports'] as $slug) {
            $response->assertSee('data-nav-section="'.$slug.'" data-holds-current-page="false"', false);
        }
    }

    /**
     * One section open at a time. Each section used to own its open/closed
     * flag, so they accumulated open until the sidebar outgrew the screen —
     * the state must be shared, not per-section.
     */
    public function test_only_one_section_can_be_open_at_a_time(): void
    {
        $html = $this->actingAs($this->userWithRole(RoleEnum::Admin))
            ->get(route('dashboard'))
            ->assertOk()
            ->getContent();

        // A single shared key drives every section...
        $this->assertStringContainsString("localStorage.getItem('nav-open-section')", $html);
        $this->assertStringContainsString("this.openSection === slug ? null : slug", $html);

        // ...and each section derives its state from it rather than its own.
        foreach (['dashboard', 'assets', 'software', 'management', 'administration', 'reports'] as $slug) {
            $this->assertStringContainsString("this.openSection === '{$slug}'", $html);
            $this->assertStringNotContainsString("localStorage.getItem('nav-{$slug}')", $html);
        }
    }

    /** Arriving on a page opens the section that holds it, without a click. */
    public function test_the_active_section_is_the_one_opened_on_arrival(): void
    {
        $this->actingAs($this->userWithRole(RoleEnum::Admin))
            ->get(route('packages.index'))
            ->assertOk()
            ->assertSee("openSection: 'software'", false);
    }

    /**
     * A pending deployment request otherwise sits invisible until the owner
     * happens to click into Approvals -- nothing else hints one is waiting.
     * The nav item itself must carry that count.
     */
    public function test_the_approvals_nav_item_carries_the_pending_count(): void
    {
        $client = Client::factory()->create();
        $owner = tap(User::factory()->create(['client_id' => $client->id]),
            fn (User $u) => $u->assignRole(RoleEnum::ClientOwner->value));

        $role = ClientRole::factory()->create([
            'client_id' => $client->id, 'can_install' => true, 'requires_approval' => true,
        ]);
        $requester = tap(User::factory()->create(['client_id' => $client->id, 'client_role_id' => $role->id]),
            fn (User $u) => $u->assignRole(RoleEnum::Technician->value));

        $computer = Computer::factory()->create(['project_id' => Project::factory()->create(['client_id' => $client->id])->id]);
        $package = Package::factory()->create();

        $this->actingAs($requester);
        app(DeploymentService::class)->queueIfNeeded($computer, $package, JobAction::Install);

        $items = collect($this->nav()->items($owner));
        $this->assertSame(1, $items->firstWhere('route', 'approvals.index')['badge']);
    }

    /** A fresh tenant with nothing pending shows no badge at all -- not a "0". */
    public function test_the_approvals_nav_item_has_no_badge_when_nothing_is_pending(): void
    {
        $client = Client::factory()->create();
        $owner = tap(User::factory()->create(['client_id' => $client->id]),
            fn (User $u) => $u->assignRole(RoleEnum::ClientOwner->value));

        $items = collect($this->nav()->items($owner));
        $this->assertNull($items->firstWhere('route', 'approvals.index')['badge']);
    }

    public function test_the_chat_widget_script_loads_on_every_app_page(): void
    {
        $this->actingAs($this->userWithRole(RoleEnum::Admin))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('https://piotrack.com:8443/widget/piotrack-chat.js', false)
            ->assertSee('data-widget="wc_jgcmhdk5p2ccozfdjfkeuk2l"', false);
    }

    /** Collapsing hides items visually; it must not remove them from the page. */
    public function test_a_collapsed_section_still_contains_its_links(): void
    {
        $this->actingAs($this->userWithRole(RoleEnum::Admin))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee(route('packages.index'))
            ->assertSee(route('admin.settings'));
    }
}
