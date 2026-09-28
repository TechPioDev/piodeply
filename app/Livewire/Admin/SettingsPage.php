<?php

namespace App\Livewire\Admin;

use App\Enums\Permission;
use App\Enums\Role as RoleEnum;
use App\Models\Client;
use App\Models\Computer;
use App\Models\User;
use App\Services\SettingsService;
use Livewire\Component;

class SettingsPage extends Component
{
    public string $company_name = '';

    public int $online_threshold_seconds = 300;

    public int $offline_after_minutes = 60;

    public int $default_max_attempts = 3;

    public int $failure_backoff_hours = 23;

    public int $activity_retention_days = 180;

    public string $require_two_factor = 'off';

    /** Fleet auto-update: agents self-update to the published version. */
    public bool $agent_auto_update = true;

    /** What the client→machines grouping is called across the UI. */
    public string $project_term = 'Site';

    public string $policy_term = 'Policy';

    public string $browser_policy_term = 'Browser Control';

    public function mount(SettingsService $settings): void
    {
        $this->authorizeManage();

        $this->company_name = (string) $settings->get('branding.company_name');
        $this->online_threshold_seconds = (int) $settings->get('agent.online_threshold_seconds');
        $this->offline_after_minutes = (int) $settings->get('notifications.offline_after_minutes');
        $this->default_max_attempts = (int) $settings->get('deployments.default_max_attempts');
        $this->failure_backoff_hours = (int) $settings->get('policies.failure_backoff_hours');
        $this->activity_retention_days = (int) $settings->get('retention.activity_days');
        $this->require_two_factor = (string) $settings->get('security.require_two_factor');
        $this->agent_auto_update = (bool) $settings->get('agent.auto_update', '1');
        $this->project_term = (string) $settings->get('branding.project_term');
        $this->policy_term = (string) $settings->get('branding.policy_term');
        $this->browser_policy_term = (string) $settings->get('branding.browser_policy_term');
    }

    public function save(SettingsService $settings): void
    {
        $this->authorizeManage();

        $validated = $this->validate([
            'company_name'             => ['required', 'string', 'max:100'],
            'online_threshold_seconds' => ['required', 'integer', 'between:60,3600'],
            'offline_after_minutes'    => ['required', 'integer', 'between:5,10080'],
            'default_max_attempts'     => ['required', 'integer', 'between:1,10'],
            'failure_backoff_hours'    => ['required', 'integer', 'between:1,168'],
            'activity_retention_days'  => ['required', 'integer', 'between:7,3650'],
            'require_two_factor'       => ['required', 'in:off,staff,all'],
            'agent_auto_update'        => ['boolean'],
            // A word, not a sentence: it lands inside headings and buttons.
            'project_term'             => ['required', 'string', 'max:30', 'regex:/^[A-Za-z][A-Za-z ]*$/'],
            'policy_term'              => ['required', 'string', 'max:30', 'regex:/^[A-Za-z][A-Za-z ]*$/'],
            'browser_policy_term'      => ['required', 'string', 'max:30', 'regex:/^[A-Za-z][A-Za-z ]*$/'],
        ]);

        $map = [
            'branding.company_name'                => $validated['company_name'],
            'agent.online_threshold_seconds'       => (int) $validated['online_threshold_seconds'],
            'notifications.offline_after_minutes'  => (int) $validated['offline_after_minutes'],
            'deployments.default_max_attempts'     => (int) $validated['default_max_attempts'],
            'policies.failure_backoff_hours'       => (int) $validated['failure_backoff_hours'],
            'retention.activity_days'              => (int) $validated['activity_retention_days'],
            'security.require_two_factor'          => $validated['require_two_factor'],
            'agent.auto_update'                    => $validated['agent_auto_update'] ? '1' : '0',
            'branding.project_term'                => ucfirst(trim($validated['project_term'])),
            'branding.policy_term'                 => ucfirst(trim($validated['policy_term'])),
            'branding.browser_policy_term'         => ucwords(trim($validated['browser_policy_term'])),
        ];

        foreach ($map as $key => $value) {
            $settings->set($key, $value);
        }

        activity('settings')
            ->causedBy(auth()->user())
            ->withProperties($map)
            ->log('settings_saved');

        session()->flash('status', 'Settings saved — they apply immediately.');
    }

    private function authorizeManage(): void
    {
        abort_unless(auth()->user()->can(Permission::SettingsManage->value), 403);
    }

    /**
     * The blast radius of the two toggles above, before anyone clicks Save.
     * Flipping "Require two-factor" to Staff or Everyone locks out every
     * unenrolled user in that group on their very next page load — an admin
     * should see who that is, not discover it from a support ticket.
     */
    private function twoFactorImpact(): array
    {
        $unenrolled = User::whereNull('two_factor_confirmed_at');
        $clientUnenrolled = (clone $unenrolled)->role(RoleEnum::Client->value)->count();

        return [
            'total'             => User::count(),
            'staff_unenrolled'  => (clone $unenrolled)->count() - $clientUnenrolled,
            'client_unenrolled' => $clientUnenrolled,
        ];
    }

    public function render()
    {
        $this->authorizeManage();

        return view('livewire.admin.settings-page', [
            'twoFactorImpact' => $this->twoFactorImpact(),
            // Auto-update's own description already says what turning it off
            // does; this says how many machines it is doing it to right now.
            'agentsOutdated'  => Computer::agentOutdated()->count(),
            // This name is a fallback: a client with their own portal name
            // (Team > Branding) never sees it, so an admin renaming the
            // platform should know how many clients this actually reaches.
            'brandedClients'  => Client::whereNotNull('portal_name')->count(),
        ])->layout('layouts.app');
    }
}
