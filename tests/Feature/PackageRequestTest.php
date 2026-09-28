<?php

namespace Tests\Feature;

use App\Enums\Role as RoleEnum;
use App\Livewire\Packages\PackageForm;
use App\Livewire\Packages\PackageRequests;
use App\Models\Client;
use App\Models\NotificationChannel;
use App\Models\PackageRequest;
use App\Models\User;
use App\Services\PackageRequestService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A client used to be able to add ANY package directly (ClientOwner already
 * held packages.manage). The MSP only wants to ship tested software, so
 * that direct create ability is withdrawn and replaced end to end: a client
 * requests, staff is notified, staff reviews (reject, or build the real
 * package via PackageForm's fulfillsRequestId), and the requester is
 * emailed the outcome either way.
 */
class PackageRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Mail::fake();
    }

    private function admin(): User
    {
        return tap(User::factory()->create(), fn (User $u) => $u->assignRole(RoleEnum::Admin->value));
    }

    private function clientOwner(?Client $client = null): User
    {
        $client ??= Client::factory()->create();
        $owner = tap(User::factory()->create(['client_id' => $client->id]),
            fn (User $u) => $u->assignRole(RoleEnum::ClientOwner->value));

        return $owner;
    }

    // ── The withdrawn direct-create ability ──────────────────────────────

    public function test_a_client_owner_can_no_longer_create_a_package_directly(): void
    {
        $owner = $this->clientOwner();

        $this->actingAs($owner)->get('/packages/create')->assertForbidden();

        Livewire::actingAs($owner)->test(PackageForm::class)->assertForbidden();
    }

    public function test_staff_can_still_create_a_package_directly(): void
    {
        $this->actingAs($this->admin())->get('/packages/create')->assertOk();
    }

    public function test_the_packages_page_offers_a_request_button_to_a_client_owner_and_new_package_to_staff(): void
    {
        // A full HTTP request, not an isolated Livewire component test — the
        // buttons live in the <x-slot name="header"> block, which only
        // renders through the real ->layout('layouts.app') composition.
        $this->actingAs($this->clientOwner())->get(route('packages.index'))
            ->assertSee('Request a package')
            ->assertDontSee('New Package');

        $this->actingAs($this->admin())->get(route('packages.index'))
            ->assertSee('New Package')
            ->assertDontSee('Request a package');
    }

    // ── Filing a request ──────────────────────────────────────────────────

    public function test_a_client_owner_can_file_a_package_request_and_staff_is_notified(): void
    {
        NotificationChannel::factory()->create(['destination' => 'ops@techpio.test', 'events' => ['package.requested']]);
        $owner = $this->clientOwner();

        Livewire::actingAs($owner)
            ->test(PackageRequests::class)
            ->set('name', 'Zoom')
            ->set('vendor', 'Zoom Video Communications')
            ->set('homepage', 'https://zoom.us')
            ->set('notes', 'Sales team needs it for client calls')
            ->call('submit')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('package_requests', [
            'client_id'    => $owner->client_id,
            'requested_by' => $owner->id,
            'name'         => 'Zoom',
            'status'       => PackageRequest::STATUS_PENDING,
        ]);
        Mail::assertSent(\App\Mail\ChannelNotification::class, 1);
    }

    public function test_a_plain_client_role_user_cannot_even_reach_the_packages_area(): void
    {
        $client = Client::factory()->create();
        $user = tap(User::factory()->create(['client_id' => $client->id]),
            fn (User $u) => $u->assignRole(RoleEnum::Client->value));

        $this->actingAs($user)->get('/packages/requests')->assertForbidden();
    }

    public function test_the_request_name_is_required(): void
    {
        Livewire::actingAs($this->clientOwner())
            ->test(PackageRequests::class)
            ->set('name', '')
            ->call('submit')
            ->assertHasErrors(['name' => 'required']);
    }

    // ── Visibility ─────────────────────────────────────────────────────────

    public function test_a_client_owner_sees_only_their_own_requests(): void
    {
        $mine = $this->clientOwner();
        $theirs = $this->clientOwner();

        app(PackageRequestService::class)->request($mine, ['name' => 'Mine App']);
        app(PackageRequestService::class)->request($theirs, ['name' => 'Theirs App']);

        Livewire::actingAs($mine)
            ->test(PackageRequests::class)
            ->assertSee('Mine App')
            ->assertDontSee('Theirs App');
    }

    public function test_staff_see_every_clients_requests(): void
    {
        $a = $this->clientOwner();
        $b = $this->clientOwner();

        app(PackageRequestService::class)->request($a, ['name' => 'App A']);
        app(PackageRequestService::class)->request($b, ['name' => 'App B']);

        Livewire::actingAs($this->admin())
            ->test(PackageRequests::class)
            ->assertSee('App A')
            ->assertSee('App B');
    }

    // ── Rejecting ──────────────────────────────────────────────────────────

    public function test_staff_can_reject_a_request_and_the_requester_is_emailed(): void
    {
        $owner = $this->clientOwner();
        $request = app(PackageRequestService::class)->request($owner, ['name' => 'Sketchy App']);

        Livewire::actingAs($this->admin())
            ->test(PackageRequests::class)
            ->call('startReject', $request->id)
            ->set('rejectionReason', 'Not a vetted vendor')
            ->call('confirmReject');

        $request->refresh();
        $this->assertSame(PackageRequest::STATUS_REJECTED, $request->status);
        $this->assertSame('Not a vetted vendor', $request->decision_note);
        Mail::assertQueued(\App\Mail\PackageRequestDecidedMail::class,
            fn ($mail) => $mail->packageRequest->is($request));
    }

    public function test_a_client_owner_cannot_reject_their_own_request(): void
    {
        $owner = $this->clientOwner();
        $request = app(PackageRequestService::class)->request($owner, ['name' => 'Self Serve App']);

        Livewire::actingAs($owner)
            ->test(PackageRequests::class)
            ->call('startReject', $request->id)
            ->assertForbidden();
    }

    public function test_a_decided_request_cannot_be_rejected_again(): void
    {
        $owner = $this->clientOwner();
        $request = app(PackageRequestService::class)->request($owner, ['name' => 'Twice App']);
        app(PackageRequestService::class)->reject($request, $this->admin(), 'first reason');

        Livewire::actingAs($this->admin())
            ->test(PackageRequests::class)
            ->call('startReject', $request->id)
            ->call('confirmReject')
            ->assertSee('already been decided');
    }

    // ── Fulfilling (approve by building the real package) ────────────────

    public function test_building_the_package_from_a_request_prefills_the_form_and_links_it(): void
    {
        $owner = $this->clientOwner();
        $request = app(PackageRequestService::class)->request($owner, [
            'name' => 'Notion', 'vendor' => 'Notion Labs', 'homepage' => 'https://notion.so',
        ]);

        $component = Livewire::actingAs($this->admin())
            ->test(PackageForm::class, ['fulfillsRequestId' => $request->id])
            ->assertSet('name', 'Notion')
            ->assertSet('vendor', 'Notion Labs')
            ->assertSet('homepage', 'https://notion.so')
            ->assertSee($owner->client->company_name); // the context banner

        $component
            ->set('package_category_id', \App\Models\PackageCategory::factory()->create()->id)
            ->set('winget_id', 'Notion.Notion')
            ->call('save');

        $request->refresh();
        $this->assertSame(PackageRequest::STATUS_APPROVED, $request->status);
        $this->assertNotNull($request->created_package_id);
        $this->assertDatabaseHas('packages', ['name' => 'Notion', 'winget_id' => 'Notion.Notion']);
        Mail::assertQueued(\App\Mail\PackageRequestDecidedMail::class,
            fn ($mail) => $mail->packageRequest->is($request) && $mail->packageRequest->created_package_id === $request->created_package_id);
    }

    public function test_a_stale_fulfills_link_is_dropped_quietly_not_a_hard_error(): void
    {
        $owner = $this->clientOwner();
        $request = app(PackageRequestService::class)->request($owner, ['name' => 'Already Done App']);
        app(PackageRequestService::class)->reject($request, $this->admin(), 'no longer needed');

        // The staff member's tab was open before the reject happened elsewhere.
        Livewire::actingAs($this->admin())
            ->test(PackageForm::class, ['fulfillsRequestId' => $request->id])
            ->assertSet('fulfillsRequestId', null)
            ->assertSet('name', ''); // no stale prefill
    }

    // ── The notification-registry bug this feature depends on ────────────

    public function test_package_requested_and_signup_received_are_real_subscribable_events(): void
    {
        // A notify() call for a key missing from NotificationChannel::EVENTS
        // silently reaches zero channels forever — this is the guard against
        // that ever happening again for either event.
        $this->assertArrayHasKey('package.requested', NotificationChannel::EVENTS);
        $this->assertArrayHasKey('signup.received', NotificationChannel::EVENTS);
    }
}
