<?php

namespace App\Services;

use App\Enums\Permission;
use App\Models\User;

/**
 * Builds the main navigation for a user based on their permissions.
 * Items for future phases are added here as their routes land; anything
 * whose route does not exist yet is filtered out automatically, so the
 * menu definition can stay ahead of the build.
 *
 * Items are grouped by the question they answer — who we manage, what we
 * push to them, how it is going, and how PioDeploy itself is configured —
 * so the menu can be scanned by section instead of read end to end.
 */
class NavigationService
{
    public const DASHBOARD = 'Dashboard';
    public const ASSETS = 'Assets';
    public const SOFTWARE = 'Software';
    public const MANAGEMENT = 'Management';
    public const REPORTS = 'Reports';
    public const BILLING = 'Billing';
    public const ADMIN = 'Administration';

    /**
     * @return list<array{label: string, route: string, active: string, group: ?string, badge: ?int}>
     */
    public function items(User $user): array
    {
        // Deployment requests from approval-gated custom roles otherwise sit
        // invisible until the owner happens to click into the Approvals
        // page — nothing else in the app hints one is waiting.
        $pendingApprovals = ($user->tenantClientId() !== null && $user->isClientOwner())
            ? \App\Models\DeploymentRequest::where('client_id', $user->tenantClientId())->where('status', 'pending')->count()
            : 0;

        // Order here is the order on screen, within a group and between them.
        $definition = [
            ['label' => 'Dashboard', 'route' => 'dashboard', 'active' => 'dashboard', 'permission' => null, 'group' => self::DASHBOARD,
                'icon' => '<path d="M3 9.5 12 3l9 6.5V20a1 1 0 0 1-1 1h-5v-6h-6v6H4a1 1 0 0 1-1-1z"/>'],
            // Failures the engine has given up retrying, grouped by cause —
            // one entry per package/machine problem, not one per machine.
            // Named outside the deployments.* family so the two nav items
            // never both light up for the same page.
            ['label' => 'Needs attention', 'route' => 'failure-queue.index', 'active' => 'failure-queue.*', 'permission' => Permission::DeploymentsView, 'group' => self::DASHBOARD,
                'icon' => '<path d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z"/>'],

            // Who and what we manage: a client owns projects, a project holds machines.
            ['label' => 'Clients', 'route' => 'clients.index', 'active' => 'clients.*', 'permission' => Permission::ClientsView, 'group' => self::ASSETS,
                'icon' => '<path d="M3 21h18M5 21V7a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v14M9 9h1M9 13h1M14 9h1M14 13h1M10 21v-4h4v4"/>'],
            ['label' => project_terms(), 'route' => 'projects.index', 'active' => 'projects.*', 'permission' => Permission::ProjectsView, 'group' => self::ASSETS,
                'icon' => '<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>'],
            ['label' => 'Computers', 'route' => 'computers.index', 'active' => 'computers.*', 'permission' => Permission::ComputersView, 'group' => self::ASSETS,
                'icon' => '<rect x="2" y="4" width="20" height="13" rx="2"/><path d="M8 21h8M12 17v4"/>'],
            ['label' => 'Device Groups', 'route' => 'computers.groups', 'active' => 'computers.groups', 'permission' => Permission::ComputersView, 'group' => self::ASSETS, 'staffOnly' => true,
                'icon' => '<path d="M17 20h5v-2a4 4 0 0 0-3-3.87M9 20H4v-2a4 4 0 0 1 3-3.87"/><circle cx="9" cy="8" r="3"/><circle cx="17" cy="9" r="2.5"/>'],

            // What we push: the catalogue and the jobs.
            ['label' => 'Packages', 'route' => 'packages.index', 'active' => 'packages.*', 'permission' => Permission::PackagesView, 'group' => self::SOFTWARE,
                'icon' => '<path d="M21 16V8a2 2 0 0 0-1-1.7l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.7l7 4a2 2 0 0 0 2 0l7-4a2 2 0 0 0 1-1.7z"/><path d="m3.3 7 8.7 5 8.7-5M12 22V12"/>'],
            ['label' => 'Deployments', 'route' => 'deployments.index', 'active' => 'deployments.*', 'permission' => Permission::DeploymentsView, 'group' => self::SOFTWARE,
                'icon' => '<path d="m22 2-7 20-4-9-9-4z"/><path d="M22 2 11 13"/>'],
            ['label' => 'Licenses', 'route' => 'licenses.index', 'active' => 'licenses.*', 'permission' => Permission::LicensesView, 'group' => self::SOFTWARE,
                'icon' => '<path d="M15 7a4 4 0 1 0-8 0c0 1.5.8 2.8 2 3.5V21l2-2 2 2v-10.5c1.2-.7 2-2 2-3.5z"/>'],

            // The rules behind what we push: desired-state and browser policy.
            ['label' => policy_terms(), 'route' => 'policies.index', 'active' => 'policies.*', 'permission' => Permission::PoliciesView, 'group' => self::MANAGEMENT,
                'icon' => '<path d="M12 22s8-3.6 8-9V5l-8-3-8 3v8c0 5.4 8 9 8 9z"/><path d="m9 12 2 2 4-4"/>'],
            ['label' => browser_policy_terms(), 'route' => 'browser-policies.index', 'active' => 'browser-policies.*', 'permission' => Permission::PoliciesView, 'group' => self::MANAGEMENT,
                'icon' => '<circle cx="12" cy="12" r="9"/><path d="M3.6 9h16.8M3.6 15h16.8M12 3a15 15 0 0 1 0 18M12 3a15 15 0 0 0 0 18"/>'],

            // Configuring PioDeploy itself — who can access it, and how.
            ['label' => 'Users', 'route' => 'admin.users', 'active' => 'admin.users*', 'permission' => Permission::UsersView, 'group' => self::ADMIN, 'staffOnly' => true,
                'icon' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.9M16 3.1a4 4 0 0 1 0 7.8"/>'],
            // A client owner's own staff — invisible to platform staff, who
            // use their own Users item above instead.
            ['label' => 'Users', 'route' => 'team.index', 'active' => 'team.*', 'permission' => Permission::UsersView, 'group' => self::ADMIN, 'tenantOnly' => true, 'ownerOnly' => true,
                'icon' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.9"/>'],
            // Deployment requests from approval-gated roles await the owner.
            ['label' => 'Approvals', 'route' => 'approvals.index', 'active' => 'approvals.*', 'permission' => Permission::UsersView, 'group' => self::ADMIN, 'tenantOnly' => true, 'ownerOnly' => true,
                'badge' => $pendingApprovals > 0 ? $pendingApprovals : null,
                'icon' => '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><path d="m9 11 3 3L22 4"/>'],
            ['label' => 'Roles', 'route' => 'admin.roles', 'active' => 'admin.roles*', 'permission' => Permission::RolesManage, 'group' => self::ADMIN,
                'icon' => '<circle cx="12" cy="8" r="3.5"/><path d="M6 21v-1.5a6 6 0 0 1 9-5.2"/><path d="m16.5 20 1.6 1 2.9-4"/>'],
            ['label' => 'Signups', 'route' => 'admin.signups', 'active' => 'admin.signups*', 'permission' => Permission::UsersCreate, 'group' => self::ADMIN,
                'icon' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M19 8v6M22 11h-6"/>'],
            ['label' => 'Enquiries', 'route' => 'admin.leads', 'active' => 'admin.leads*', 'permission' => Permission::SettingsManage, 'group' => self::ADMIN,
                'icon' => '<path d="M4 4h16v16H4z"/><path d="m4 7 8 6 8-6"/>'],
            ['label' => 'Notifications', 'route' => 'admin.notifications', 'active' => 'admin.notifications*', 'permission' => Permission::SettingsManage, 'group' => self::ADMIN,
                'icon' => '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/>'],
            ['label' => 'Email', 'route' => 'admin.mail', 'active' => 'admin.mail*', 'permission' => Permission::SettingsManage, 'group' => self::ADMIN,
                'icon' => '<path d="m22 7-10 6L2 7"/><rect x="2" y="5" width="20" height="14" rx="2"/>'],
            ['label' => 'Website', 'route' => 'admin.content', 'active' => 'admin.content*', 'permission' => Permission::SettingsManage, 'group' => self::ADMIN,
                'icon' => '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 9h18M7 4v5"/>'],

            // How it is going.
            ['label' => 'Reports', 'route' => 'reports.index', 'active' => 'reports.*', 'permission' => Permission::ReportsView, 'group' => self::REPORTS,
                'icon' => '<path d="M3 3v18h18"/><path d="m7 14 4-4 4 4 5-6"/>'],
            // Same page as before (route: activity.index) — "Audit Logs" is
            // what it actually is; "Activity" undersold it.
            ['label' => 'Audit Logs', 'route' => 'activity.index', 'active' => 'activity.*', 'permission' => Permission::ActivityView, 'group' => self::REPORTS,
                'icon' => '<path d="M22 12h-4l-3 9L9 3l-3 9H2"/>'],
            // Subscriptions, revenue, growth (Billing system).
            ['label' => 'Overview', 'route' => 'admin.billing-overview', 'active' => 'admin.billing-overview*', 'permission' => Permission::SettingsManage, 'group' => self::BILLING,
                'icon' => '<path d="M3 3v18h18"/><path d="m7 14 4-4 4 4 5-6"/>'],
            // "My" is load-bearing: these two are THIS company's own
            // subscription to PioDeploy, not customer money. Unqualified
            // "Subscription" / "Invoices" in a billing menu reads as the
            // customers' — and then an empty page looks like lost records.
            ['label' => 'My subscription', 'route' => 'billing.subscription', 'active' => 'billing.subscription*', 'permission' => Permission::SettingsManage, 'group' => self::BILLING,
                'icon' => '<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/>'],
            ['label' => 'My invoices', 'route' => 'billing.invoices', 'active' => 'billing.invoices*', 'permission' => Permission::SettingsManage, 'group' => self::BILLING,
                'icon' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M9 13h6M9 17h6"/>'],
            ['label' => 'Coupons', 'route' => 'admin.coupons', 'active' => 'admin.coupons*', 'permission' => Permission::SettingsManage, 'group' => self::BILLING,
                'icon' => '<path d="M20 12a2 2 0 0 1 2-2V7a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v3a2 2 0 0 1 0 4v3a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-3a2 2 0 0 1-2-2z"/>'],
            ['label' => 'Affiliates', 'route' => 'admin.affiliates', 'active' => 'admin.affiliates*', 'permission' => Permission::SettingsManage, 'group' => self::BILLING,
                'icon' => '<circle cx="9" cy="7" r="3"/><path d="M3 21v-2a4 4 0 0 1 4-4h4a4 4 0 0 1 4 4v2"/><path d="M16 3.1a4 4 0 0 1 0 7.8"/>'],
            ['label' => 'Webhooks', 'route' => 'admin.webhooks', 'active' => 'admin.webhooks*', 'permission' => Permission::SettingsManage, 'group' => self::BILLING,
                'icon' => '<path d="M18 16.98h-5.99c-1.66 0-3.01-1.34-3.01-3S10.35 11 12 11h.01M6 8a4 4 0 1 0 4 4M15 16a4 4 0 1 0-4-4"/>'],
            ['label' => 'Billing settings', 'route' => 'admin.billing', 'active' => 'admin.billing', 'permission' => Permission::SettingsManage, 'group' => self::BILLING,
                'icon' => '<circle cx="12" cy="12" r="3"/><path d="M12 3v2M12 19v2M5 12H3M21 12h-2"/>'],
            // A client owner's own subscription — money, not fleet: it sits
            // last in their menu, its own section at the bottom. Staff manage
            // billing from Administration, so this stays tenant-only.
            // (Defined last on purpose: groups render in first-appearance
            // order, and for a tenant this is the first Billing-group item.)
            // What their users and machines see: portal name, tray identity.
            ['label' => 'Branding', 'route' => 'branding.index', 'active' => 'branding.*', 'permission' => Permission::UsersView, 'group' => self::BILLING, 'tenantOnly' => true, 'ownerOnly' => true,
                'icon' => '<path d="M12 2a10 10 0 1 0 0 20c1 0 1.7-.8 1.7-1.7 0-.5-.2-.8-.5-1.1-.2-.3-.4-.7-.4-1.2 0-.9.7-1.7 1.7-1.7h2c3 0 5.5-2.5 5.5-5.6C22 6 17.5 2 12 2z"/><circle cx="7" cy="12" r="1"/><circle cx="9" cy="8" r="1"/><circle cx="14" cy="7" r="1"/><circle cx="17.5" cy="10.5" r="1"/>'],
            ['label' => 'Billing', 'route' => 'tenant.billing', 'active' => 'tenant.billing', 'permission' => Permission::UsersView, 'group' => self::BILLING, 'tenantOnly' => true, 'ownerOnly' => true,
                'icon' => '<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/>'],

            ['label' => 'Settings', 'route' => 'admin.settings', 'active' => 'admin.settings*', 'permission' => Permission::SettingsManage, 'group' => self::ADMIN,
                'icon' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09a1.65 1.65 0 0 0-1-1.51 1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09a1.65 1.65 0 0 0 1.51-1 1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33h.01a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51h.01a1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82v.01a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>'],
        ];

        return collect($definition)
            ->filter(fn (array $item) => \Illuminate\Support\Facades\Route::has($item['route']))
            ->filter(fn (array $item) => $item['permission'] === null || $user->can($item['permission']->value))
            // Cross-client surfaces (device groups) never appear for tenants.
            ->filter(fn (array $item) => ! ($item['staffOnly'] ?? false) || $user->tenantClientId() === null)
            ->filter(fn (array $item) => ! ($item['tenantOnly'] ?? false) || $user->tenantClientId() !== null)
            // Team and Billing belong to the account owner: a Manager runs
            // the fleet, so a link they would only be refused is worse than
            // no link at all.
            ->filter(fn (array $item) => ! ($item['ownerOnly'] ?? false) || $user->isClientOwner())
            ->map(fn (array $item) => [
                'label'  => $item['label'],
                'route'  => $item['route'],
                'active' => $item['active'],
                'icon'   => $item['icon'] ?? '',
                'group'  => $item['group'] ?? null,
                'badge'  => $item['badge'] ?? null,
            ])
            ->values()
            ->all();
    }

    /**
     * The same items, in their sections. A group the user cannot see any of
     * disappears with its heading rather than leaving an empty label.
     *
     * @return list<array{label: ?string, items: list<array{label: string, route: string, active: string}>}>
     */
    public function groups(User $user): array
    {
        return collect($this->items($user))
            ->groupBy(fn (array $item) => $item['group'] ?? '')
            ->map(fn ($items, string $label) => [
                'label' => $label === '' ? null : $label,
                'items' => $items->values()->all(),
            ])
            ->values()
            ->all();
    }
}
