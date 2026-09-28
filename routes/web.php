<?php

use Illuminate\Support\Facades\Route;

// Public marketing site.
Route::get('/', [\App\Http\Controllers\MarketingController::class, 'home'])->name('home');
Route::get('/sitemap.xml', [\App\Http\Controllers\SeoController::class, 'sitemap'])->name('sitemap');
Route::get('/robots.txt', [\App\Http\Controllers\SeoController::class, 'robots'])->name('robots');

Route::get('/features', [\App\Http\Controllers\MarketingController::class, 'features'])->name('features');
Route::get('/about', [\App\Http\Controllers\MarketingController::class, 'about'])->name('about');
Route::get('/pricing', [\App\Http\Controllers\MarketingController::class, 'pricing'])->name('pricing');
Route::get('/contact', [\App\Http\Controllers\MarketingController::class, 'contact'])->name('contact');
Route::get('/privacy', [\App\Http\Controllers\MarketingController::class, 'privacy'])->name('privacy');
Route::get('/get-started', [\App\Http\Controllers\MarketingController::class, 'getStarted'])->name('get-started');
Route::get('/brand', [\App\Http\Controllers\MarketingController::class, 'brand'])->name('brand');
Route::post('/leads', [\App\Http\Controllers\MarketingController::class, 'storeLead'])
    ->middleware('throttle:6,1')->name('leads.store');

// Self-service signup: multi-step wizard -> payment -> admin approval.
Route::get('/signup', \App\Livewire\Marketing\SignupWizard::class)->name('signup');
Route::get('/signup/thanks', fn () => view('marketing.signup-thanks'))->name('signup.thanks');
Route::post('/quote', [\App\Http\Controllers\MarketingController::class, 'storeQuote'])
    ->middleware('throttle:6,1')->name('quotes.store');

// Billing (Stripe Checkout). Webhook is CSRF-exempt (see bootstrap/app.php)
// and HMAC-verified instead.
Route::post('/billing/checkout', [\App\Http\Controllers\BillingController::class, 'checkout'])
    ->middleware('throttle:12,1')->name('billing.checkout');
Route::get('/billing/success', [\App\Http\Controllers\BillingController::class, 'success'])->name('billing.success');
Route::post('/billing/webhook', [\App\Http\Controllers\BillingController::class, 'webhook'])->name('billing.webhook');

// Cashier/subscription webhook — signature-verified, idempotent, logged.
Route::post('/stripe/webhook', \App\Http\Controllers\StripeWebhookController::class)->name('stripe.webhook');

// Public agent download (the token is the secret; keys are never embedded).
Route::middleware('throttle:30,1')->group(function () {
    Route::get('/download/agent/{token}', [\App\Http\Controllers\AgentDownloadController::class, 'script'])
        ->name('agent.download');
    Route::get('/download/agent/{token}/binary', [\App\Http\Controllers\AgentDownloadController::class, 'binary'])
        ->name('agent.download.binary');
});

Route::middleware([
    'auth:sanctum',
    config('jetstream.auth_session'),
    'verified',
])->group(function () {
    Route::get('/dashboard', \App\Livewire\Dashboard::class)->name('dashboard');

    Route::get('/admin/users', \App\Livewire\Admin\ManageUsers::class)
        ->middleware('permission:users.view')
        ->name('admin.users');

    Route::get('/admin/roles', \App\Livewire\Admin\ManageRoles::class)
        ->middleware('permission:roles.manage')
        ->name('admin.roles');

    Route::get('/admin/notifications', \App\Livewire\Admin\NotificationChannels::class)
        ->middleware('permission:settings.manage')
        ->name('admin.notifications');

    Route::get('/activity', \App\Livewire\Admin\ActivityIndex::class)
        ->middleware('permission:activity.view')
        ->name('activity.index');

    Route::get('/admin/settings', \App\Livewire\Admin\SettingsPage::class)
        ->middleware('permission:settings.manage')
        ->name('admin.settings');

    // Subscription & billing (per MSP account).
    Route::get('/billing/subscription', \App\Livewire\Billing\Subscription::class)
        ->middleware('permission:settings.manage')
        ->name('billing.subscription');

    Route::get('/billing/invoices', \App\Livewire\Billing\Portal::class)
        ->middleware('permission:settings.manage')
        ->name('billing.invoices');
    Route::get('/billing/invoices/{invoiceId}/download', [\App\Http\Controllers\BillingInvoiceController::class, 'download'])
        ->middleware('permission:settings.manage')
        ->name('billing.invoices.download');

    Route::get('/admin/webhooks', \App\Livewire\Admin\WebhookEvents::class)
        ->middleware('permission:settings.manage')
        ->name('admin.webhooks');

    Route::get('/admin/coupons', \App\Livewire\Admin\Coupons::class)
        ->middleware('permission:settings.manage')
        ->name('admin.coupons');

    Route::get('/admin/billing-overview', \App\Livewire\Admin\BillingDashboard::class)
        ->middleware('permission:settings.manage')
        ->name('admin.billing-overview');
    Route::get('/admin/billing-overview/export', [\App\Http\Controllers\BillingExportController::class, 'payments'])
        ->middleware('permission:settings.manage')
        ->name('billing.export');

    Route::get('/admin/affiliates', \App\Livewire\Admin\Affiliates::class)
        ->middleware('permission:settings.manage')
        ->name('admin.affiliates');
    Route::get('/admin/affiliates/export', [\App\Http\Controllers\AffiliateExportController::class, 'commissions'])
        ->middleware('permission:settings.manage')
        ->name('affiliates.export');

    // An affiliate's own dashboard (any signed-in user; shows a notice if not one).
    Route::get('/affiliate', \App\Livewire\Affiliate\Dashboard::class)->name('affiliate.dashboard');

    Route::get('/admin/content', \App\Livewire\Admin\ManageContent::class)
        ->middleware('permission:settings.manage')
        ->name('admin.content');

    Route::get('/admin/billing', \App\Livewire\Admin\BillingSettings::class)
        ->middleware('permission:settings.manage')
        ->name('admin.billing');

    Route::post('/admin/impersonate/{user}', [\App\Http\Controllers\ImpersonationController::class, 'start'])
        ->middleware('role:Super Admin')
        ->name('impersonate.start');
    // The start form opens a new tab, so this URL sits in that tab's
    // history: refreshing or reopening it issues a GET. Starting an
    // impersonation from a GET would be CSRF-able, so this changes nothing
    // — it just lands the person somewhere sensible instead of a raw 405.
    Route::get('/admin/impersonate/{user}', [\App\Http\Controllers\ImpersonationController::class, 'landing']);
    Route::post('/impersonate/leave', [\App\Http\Controllers\ImpersonationController::class, 'leave'])
        ->name('impersonate.leave');

    Route::middleware('permission:clients.view')->group(function () {
        Route::get('/clients', \App\Livewire\Clients\ClientsIndex::class)->name('clients.index');
        Route::get('/clients/create', \App\Livewire\Clients\ClientForm::class)->name('clients.create');
        Route::get('/clients/{client}/edit', \App\Livewire\Clients\ClientForm::class)->name('clients.edit');
    });

    Route::middleware('permission:projects.view')->group(function () {
        Route::get('/projects', \App\Livewire\Projects\ProjectsIndex::class)->name('projects.index');
        Route::get('/projects/create', \App\Livewire\Projects\ProjectForm::class)->name('projects.create');
        Route::get('/projects/{project}/edit', \App\Livewire\Projects\ProjectForm::class)->name('projects.edit');
        Route::get('/projects/{project}/enrollment', \App\Livewire\Projects\ProjectEnrollment::class)
            ->name('projects.enrollment');
    });

    // Everything the website sent us. Gated inside the component too.
    Route::get('/admin/enquiries', \App\Livewire\Admin\LeadsIndex::class)->name('admin.leads');

    // Self-service signups awaiting payment verification + approval.
    Route::get('/admin/signups', \App\Livewire\Admin\SignupsIndex::class)->name('admin.signups');

    // A client owner's own staff. Tenancy enforced inside the component.
    Route::get('/team', \App\Livewire\Team\TeamIndex::class)->name('team.index');
    Route::get('/team/roles', \App\Livewire\Team\ClientRoles::class)->name('team.roles');
    Route::get('/approvals', \App\Livewire\Team\Approvals::class)->name('approvals.index');
    Route::get('/branding', \App\Livewire\Team\Branding::class)->name('branding.index');

    // A client owner's own subscription (tenant-only, resolved from their
    // binding; card actions happen on Stripe's hosted portal).
    Route::get('/my-billing', \App\Livewire\Clients\TenantBilling::class)->name('tenant.billing');

    // SMTP without an SSH session. Gated inside the component too.
    Route::get('/admin/email', \App\Livewire\Admin\MailSettings::class)
        ->middleware('permission:settings.manage')->name('admin.mail');

    Route::middleware('permission:computers.view')->group(function () {
        Route::get('/computers', \App\Livewire\Computers\ComputersIndex::class)->name('computers.index');
        // Before {computer}, or "groups" would be swallowed by the binding.
        Route::get('/computers/groups', \App\Livewire\Computers\ComputerGroups::class)->name('computers.groups');
        Route::get('/computers/{computer}', \App\Livewire\Computers\ComputerShow::class)->name('computers.show');
        Route::get('/computers/{computer}/edit', \App\Livewire\Computers\ComputerEdit::class)->name('computers.edit');
    });

    Route::middleware('permission:packages.view')->group(function () {
        Route::get('/packages', \App\Livewire\Packages\PackagesIndex::class)->name('packages.index');
        // Before {package}, or "requests" would be swallowed by the binding.
        Route::get('/packages/requests', \App\Livewire\Packages\PackageRequests::class)->name('packages.requests');
        Route::get('/packages/create', \App\Livewire\Packages\PackageForm::class)->name('packages.create');
        Route::get('/packages/{package}', \App\Livewire\Packages\PackageShow::class)->name('packages.show');
        Route::get('/packages/{package}/edit', \App\Livewire\Packages\PackageForm::class)->name('packages.edit');
    });

    // Paid-license register. Tenancy + key secrecy enforced in the component
    // and model (revealKeyFor refuses everyone outside the owning tenant).
    Route::get('/licenses', \App\Livewire\Licenses\LicensesIndex::class)
        ->middleware('permission:licenses.view')->name('licenses.index');

    Route::middleware('permission:deployments.view')->group(function () {
        Route::get('/deployments', \App\Livewire\Deployments\DeploymentsIndex::class)->name('deployments.index');
        Route::get('/deployments/bulk', \App\Livewire\Deployments\BulkDeploy::class)->name('deployments.bulk');
        Route::get('/deployments/needs-attention', \App\Livewire\Deployments\FailureQueue::class)->name('failure-queue.index');
    });

    // The controller authorizes internally: staff need reports.view, while a
    // client-portal user may download their own client's report.
    Route::get('/clients/{client}/compliance-report', [\App\Http\Controllers\ClientComplianceReportController::class, 'download'])
        ->name('clients.compliance-report');

    Route::middleware('permission:reports.view')->group(function () {
        Route::get('/reports', \App\Livewire\Reports\ReportsIndex::class)->name('reports.index');
        Route::get('/reports/compliance', \App\Livewire\Reports\ComplianceReport::class)->name('reports.compliance');
        Route::get('/reports/deployments', \App\Livewire\Reports\DeploymentsReport::class)->name('reports.deployments');
        Route::get('/reports/computers', \App\Livewire\Reports\ComputersReport::class)->name('reports.computers');
    });

    Route::middleware('permission:policies.view')->group(function () {
        Route::get('/policies', \App\Livewire\Policies\PoliciesIndex::class)->name('policies.index');
        Route::get('/policies/templates', \App\Livewire\Policies\PolicyTemplates::class)->name('policies.templates');
        Route::get('/policies/create', \App\Livewire\Policies\PolicyForm::class)->name('policies.create');
        Route::get('/policies/{policy}', \App\Livewire\Policies\PolicyShow::class)->name('policies.show');
        Route::get('/policies/{policy}/edit', \App\Livewire\Policies\PolicyForm::class)->name('policies.edit');

        Route::get('/browser-policies', \App\Livewire\BrowserPolicies\BrowserPoliciesIndex::class)->name('browser-policies.index');
        Route::get('/browser-policies/create', \App\Livewire\BrowserPolicies\BrowserPolicyForm::class)->name('browser-policies.create');
        // Before {policy}, or these would be swallowed by the binding.
        Route::get('/browser-policies/templates', \App\Livewire\BrowserPolicies\BrowserPolicyTemplates::class)->name('browser-policies.templates');
        Route::get('/browser-policies/compliance', \App\Livewire\BrowserPolicies\BrowserPolicyCompliance::class)->name('browser-policies.compliance');
        Route::get('/browser-policies/export/project/{project}', [\App\Http\Controllers\BrowserPolicyExportController::class, 'project'])->name('browser-policies.export.project');
        Route::get('/browser-policies/export/template/{key}', [\App\Http\Controllers\BrowserPolicyExportController::class, 'template'])->name('browser-policies.export.template');
        Route::get('/browser-policies/{policy}', \App\Livewire\BrowserPolicies\BrowserPolicyShow::class)->name('browser-policies.show');
        Route::get('/browser-policies/{policy}/edit', \App\Livewire\BrowserPolicies\BrowserPolicyForm::class)->name('browser-policies.edit');
    });
});
