<?php

namespace Tests\Feature;

use App\Enums\Role as RoleEnum;
use App\Livewire\Admin\SignupsIndex;
use App\Livewire\Marketing\SignupWizard;
use App\Mail\AccountApprovedMail;
use App\Models\Signup;
use App\Models\User;
use App\Services\SignupApprovalService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The pricing-page-to-working-account pipeline: wizard -> application ->
 * payment -> admin approval -> welcome mail -> tenant-scoped login.
 */
class SignupFlowTest extends TestCase
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

    private function completedWizard()
    {
        return Livewire::test(SignupWizard::class)
            ->set('machines', 40)
            ->call('next')
            ->set('contact_name', 'Priya Shah')
            ->set('email', 'priya@acme-msp.example')
            ->set('password', 'S3cure-password-1')
            ->set('password_confirmation', 'S3cure-password-1')
            ->call('next')
            ->set('company_name', 'Acme MSP Ltd')
            ->set('phone', '+44 20 7000 0000')
            ->call('next');
    }

    public function test_the_wizard_creates_an_application(): void
    {
        // Stripe unconfigured in tests -> lands as awaiting_verification.
        $this->completedWizard()
            ->call('submit')
            ->assertRedirect(route('signup.thanks'));

        $signup = Signup::sole();
        $this->assertSame('Acme MSP Ltd', $signup->company_name);
        $this->assertSame(Signup::STATUS_AWAITING_VERIFICATION, $signup->status);
        $this->assertSame(40, $signup->machines);
        $this->assertTrue(Hash::check('S3cure-password-1', $signup->password_hash), 'the chosen password is stored hashed');
        // Nothing real exists yet — approval creates the account.
        $this->assertDatabaseMissing('users', ['email' => 'priya@acme-msp.example']);
        $this->assertDatabaseMissing('clients', ['company_name' => 'Acme MSP Ltd']);
    }

    public function test_choosing_invoice_skips_stripe_even_when_configured(): void
    {
        // The accounts-department path: Stripe is live, but the applicant
        // asked for an invoice — no checkout redirect, and the admin queue
        // must know this was a choice, not a missing configuration.
        config(['services.stripe.key' => 'pk_test_x', 'services.stripe.secret' => 'sk_test_x']);

        $this->completedWizard()
            ->set('payVia', 'invoice')
            ->call('submit')
            ->assertRedirect(route('signup.thanks'));

        $signup = Signup::sole();
        $this->assertSame(Signup::STATUS_AWAITING_VERIFICATION, $signup->status);
        $this->assertSame('invoice', $signup->payment_method);
    }

    public function test_card_stays_the_default_when_stripe_is_configured(): void
    {
        config(['services.stripe.key' => 'pk_test_x', 'services.stripe.secret' => 'sk_test_x']);
        \Illuminate\Support\Facades\Http::fake([
            'api.stripe.com/*' => \Illuminate\Support\Facades\Http::response(['id' => 'cs_1', 'url' => 'https://checkout.stripe.com/c/pay/cs_1']),
        ]);

        $this->completedWizard()->call('submit')
            ->assertRedirect('https://checkout.stripe.com/c/pay/cs_1');

        $signup = Signup::sole();
        $this->assertSame(Signup::STATUS_PENDING_PAYMENT, $signup->status);
        $this->assertSame('card', $signup->payment_method);
    }

    public function test_each_step_validates_before_advancing(): void
    {
        Livewire::test(SignupWizard::class)
            ->set('machines', 0)
            ->call('next')
            ->assertHasErrors('machines')
            ->assertSet('step', 1);

        Livewire::test(SignupWizard::class)
            ->call('next') // step 1 ok with default
            ->set('email', 'not-an-email')
            ->set('password', 'short')
            ->call('next')
            ->assertHasErrors(['email', 'password'])
            ->assertSet('step', 2);
    }

    public function test_an_existing_users_email_cannot_sign_up_again(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->completedWizard()
            ->set('step', 2)
            ->set('email', 'taken@example.com')
            ->call('next')
            ->assertHasErrors('email');
    }

    public function test_approval_creates_the_client_the_owner_and_sends_the_mail(): void
    {
        Mail::fake();
        $signup = Signup::factory()->paid()->create([
            'email' => 'owner@newclient.example', 'company_name' => 'New Client GmbH',
            'password_hash' => Hash::make('Owner-pass-123'),
        ]);

        Livewire::actingAs($this->admin())
            ->test(SignupsIndex::class)
            ->call('approve', $signup->id);

        $signup->refresh();
        $this->assertSame(Signup::STATUS_APPROVED, $signup->status);
        $this->assertNotNull($signup->client_id);

        $owner = User::where('email', 'owner@newclient.example')->sole();
        $this->assertSame($signup->client_id, $owner->client_id, 'the owner is bound to the new tenant');
        $this->assertTrue($owner->hasRole(RoleEnum::ClientOwner->value), 'signup owners are Client Owners, not staff Managers');
        $this->assertTrue(Hash::check('Owner-pass-123', $owner->password), 'they log in with the password they chose at signup');

        // queue(), not send(): a synchronous send() 500s the whole approval
        // on any SMTP hiccup, even though the account above already
        // committed. assertQueued (not assertSent) is what proves this.
        Mail::assertQueued(AccountApprovedMail::class, fn ($mail) => $mail->hasTo('owner@newclient.example'));
    }

    /**
     * Reproduces the production incident directly: SignupApprovalService
     * used to call Mail::send(), so a real SMTP rejection (a sender address
     * Hostinger wouldn't accept) threw an uncaught exception straight out of
     * the Livewire action and 500'd the page -- after the DB transaction had
     * already committed the client and owner. The admin saw a server error
     * with no idea the account existed; the new owner never got their
     * "account is ready" email at all. Pinning the exact call (queue(), not
     * send()) is what catches a regression back to the crashing path -- a
     * fake mailer can't reproduce the SMTP failure itself, since it never
     * talks to a real transport either way.
     */
    public function test_approval_hands_the_mail_to_the_queue_not_a_synchronous_send(): void
    {
        Mail::shouldReceive('to')->once()->with('owner@newclient.example')->andReturnSelf();
        Mail::shouldReceive('queue')->once()->with(\Mockery::type(AccountApprovedMail::class));
        Mail::shouldNotReceive('send');

        $signup = Signup::factory()->paid()->create([
            'email' => 'owner@newclient.example', 'company_name' => 'New Client GmbH',
            'password_hash' => Hash::make('Owner-pass-123'),
        ]);

        app(SignupApprovalService::class)->approve($signup, $this->admin());

        $this->assertSame(Signup::STATUS_APPROVED, $signup->fresh()->status);
        $this->assertNotNull(User::where('email', 'owner@newclient.example')->first(), 'the account is created regardless of mail delivery');
    }

    public function test_the_approved_owner_can_log_in_and_is_tenant_scoped(): void
    {
        Mail::fake();
        $signup = Signup::factory()->paid()->create();
        app(SignupApprovalService::class)->approve($signup, $this->admin());

        $owner = User::where('email', $signup->email)->sole();

        $this->actingAs($owner)->get('/dashboard')->assertOk();
        $this->assertSame($signup->client_id, $owner->tenantClientId());
    }

    public function test_a_decided_signup_cannot_be_decided_again(): void
    {
        Mail::fake();
        $signup = Signup::factory()->paid()->create();
        $admin = $this->admin();
        $service = app(SignupApprovalService::class);

        $service->approve($signup, $admin);

        $this->expectException(\DomainException::class);
        $service->approve($signup->fresh(), $admin);
    }

    public function test_rejection_records_the_reason_and_creates_nothing(): void
    {
        $signup = Signup::factory()->create();

        Livewire::actingAs($this->admin())
            ->test(SignupsIndex::class)
            ->call('startReject', $signup->id)
            ->set('rejectionReason', 'Payment never arrived')
            ->call('confirmReject');

        $signup->refresh();
        $this->assertSame(Signup::STATUS_REJECTED, $signup->status);
        $this->assertSame('Payment never arrived', $signup->rejection_reason);
        $this->assertDatabaseMissing('users', ['email' => $signup->email]);
    }

    public function test_the_signups_page_is_permission_gated(): void
    {
        $viewer = tap(User::factory()->create(), fn (User $u) => $u->assignRole(RoleEnum::Viewer->value));

        $this->actingAs($viewer)->get('/admin/signups')->assertForbidden();
        $this->actingAs($this->admin())->get('/admin/signups')->assertOk();
    }

    public function test_the_stripe_webhook_marks_the_signup_paid(): void
    {
        $signup = Signup::factory()->create(); // pending_payment
        $secret = 'whsec_test';
        config(['services.stripe.webhook_secret' => $secret]);

        $payload = json_encode([
            'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'id' => 'cs_test_signup_1',
                'payment_status' => 'paid',
                'amount_total' => $signup->monthly_cents,
                'currency' => 'usd',
                'customer_details' => ['email' => $signup->email],
                'metadata' => ['machines' => $signup->machines, 'signup_id' => $signup->id],
                'mode' => 'subscription',
            ]],
        ]);
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);

        $this->call('POST', '/billing/webhook', [], [], [], [
            'HTTP_Stripe-Signature' => "t={$timestamp},v1={$signature}",
            'CONTENT_TYPE' => 'application/json',
        ], $payload)->assertOk();

        $signup->refresh();
        $this->assertSame(Signup::STATUS_PAID, $signup->status);
        $this->assertSame('cs_test_signup_1', $signup->payment_reference);
        $this->assertNotNull($signup->paid_at);
    }

    public function test_the_public_wizard_page_renders_from_the_pricing_cta(): void
    {
        $this->get('/signup?machines=250')
            ->assertOk()
            ->assertSee('Create your PioDeploy account');
    }
}
