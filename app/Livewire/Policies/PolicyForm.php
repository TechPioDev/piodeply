<?php

namespace App\Livewire\Policies;

use App\Enums\PolicyAction;
use App\Enums\PolicyMode;
use App\Enums\PolicyVersionMode;
use App\Models\Computer;
use App\Models\ComputerGroup;
use App\Models\Package;
use App\Models\Project;
use App\Models\SoftwarePolicy;
use Illuminate\Validation\Rule;
use Livewire\Component;

class PolicyForm extends Component
{
    public ?SoftwarePolicy $policy = null;

    /**
     * Assignment: what kind of thing the policy targets. 'project' is by far
     * the common case and keeps its own dedicated property below (existing
     * tests and call sites already speak project_id directly); scope_id is
     * only ever read for the two new kinds.
     */
    public string $scope_type = 'project';

    public ?int $scope_id = null;

    public ?int $project_id = null;

    /** Edit mode only — creation uses the multi-select below. */
    public ?int $package_id = null;

    /**
     * Creation: every selected package gets its own policy (the engine is
     * per-package by design — rings, cooldowns and compliance all key on
     * one package). One form, many rules.
     *
     * @var list<int>
     */
    public array $packageIds = [];

    public string $packageSearch = '';

    public string $action = 'install';

    public string $mode = 'enforce';

    public string $version_mode = 'latest';

    public ?string $desired_version = null;

    public int $priority = 5;

    public string $frequency = 'daily';

    /** @var list<int> ISO weekdays 1 (Mon) – 7 (Sun); empty = anytime */
    public array $window_days = [];

    public ?string $window_start = null;

    public ?string $window_end = null;

    public int $test_delay_days = 0;

    public int $production_delay_days = 0;

    public function mount(?SoftwarePolicy $policy = null): void
    {
        if ($policy !== null && $policy->exists) {
            $this->authorize('update', $policy);
            $this->policy = $policy;
            $this->scope_type = $policy->scope_type;
            $this->project_id = $policy->project_id;
            $this->scope_id = $policy->scope_type === 'project' ? null : $policy->scope_id;
            $this->package_id = $policy->package_id;
            $this->action = $policy->action->value;
            $this->mode = $policy->mode->value;
            $this->version_mode = $policy->version_mode->value;
            $this->desired_version = $policy->desired_version;
            $this->priority = $policy->priority;
            $this->frequency = $policy->frequency?->value ?? 'daily';
            $this->window_days = $policy->window_days ?? [];
            $this->window_start = $policy->window_start ? substr($policy->window_start, 0, 5) : null;
            $this->window_end = $policy->window_end ? substr($policy->window_end, 0, 5) : null;
            $this->test_delay_days = $policy->test_delay_days ?? 0;
            $this->production_delay_days = $policy->production_delay_days ?? 0;
        } else {
            $this->authorize('create', SoftwarePolicy::class);
        }
    }

    /** Changing the scope kind invalidates whatever the previous kind had picked. */
    public function updatedScopeType(): void
    {
        $this->project_id = null;
        $this->scope_id = null;
    }

    public function addPackage(int $packageId): void
    {
        $exists = Package::active()->visibleTo(auth()->user())->whereKey($packageId)->exists();

        if ($exists && ! in_array($packageId, $this->packageIds, true)) {
            $this->packageIds[] = $packageId;
        }

        $this->packageSearch = '';
    }

    public function removePackage(int $packageId): void
    {
        $this->packageIds = array_values(array_diff($this->packageIds, [$packageId]));
    }

    public function save()
    {
        $this->authorize($this->policy ? 'update' : 'create', $this->policy ?? SoftwarePolicy::class);

        $scopeIdRule = match ($this->scope_type) {
            'group'    => ['required', 'integer', Rule::exists('computer_groups', 'id')],
            'computer' => ['required', 'integer', Rule::exists('computers', 'id')],
            default    => ['nullable'],
        };

        $validated = $this->validate([
            'scope_type'      => ['required', Rule::in(SoftwarePolicy::SCOPE_TYPES)],
            'project_id'      => [Rule::requiredIf($this->scope_type === 'project'), 'nullable', 'integer', Rule::exists('projects', 'id')->withoutTrashed()],
            'scope_id'        => $scopeIdRule,
            'action'          => ['required', Rule::in(PolicyAction::values())],
            'mode'            => ['required', Rule::in(PolicyMode::values())],
            'version_mode'    => ['required', Rule::in(PolicyVersionMode::values())],
            'desired_version' => ['nullable', 'string', 'max:100', 'regex:/^[0-9][0-9A-Za-z.\-+]*$/'],
            'priority'        => ['required', 'integer', 'between:1,10'],
            'frequency'       => ['required', Rule::in(\App\Enums\PolicyFrequency::values())],
            'window_days'     => ['array'],
            'window_days.*'   => ['integer', 'between:1,7'],
            'window_start'    => ['nullable', 'date_format:H:i', 'required_with:window_end'],
            'window_end'      => ['nullable', 'date_format:H:i', 'required_with:window_start'],
            'test_delay_days'       => ['required', 'integer', 'between:0,365'],
            'production_delay_days' => ['required', 'integer', 'between:0,365'],
        ], [
            'desired_version.regex' => 'Versions look like 24.09 or 139.0.7258.67.',
        ], [
            'project_id'      => project_term_lower(),
            'scope_id'        => 'target',
            'desired_version' => 'version',
            'window_start'    => 'window start',
            'window_end'      => 'window end',
        ]);

        // A window needs both days and times; days without times (or vice
        // versa) is half a window.
        if ($validated['window_days'] !== [] && ($validated['window_start'] === null || $validated['window_end'] === null)) {
            $this->addError('window_start', 'Pick a start and end time for the maintenance window.');

            return null;
        }
        if ($validated['window_days'] === []) {
            $validated['window_start'] = null;
            $validated['window_end'] = null;
            $validated['window_days'] = null;
        } else {
            $validated['window_days'] = array_values(array_map('intval', $validated['window_days']));
        }

        $versionMode = PolicyVersionMode::from($validated['version_mode']);
        $action = PolicyAction::from($validated['action']);

        // Tenancy: whatever this policy's scope reaches must be machines the
        // user may actually touch — hiding a foreign target from the picker
        // is presentation, refusing the id is the boundary. A group or a
        // single machine can belong to another client entirely (a group by
        // design can span clients), so this cannot be a single-project
        // lookup the way it used to be: every distinct project the scope
        // actually reaches is checked, both for tenancy and, below, for
        // whether the package may run there at all.
        $tenantId = auth()->user()->tenantClientId();

        if ($this->scope_type === 'project') {
            $targetProject = Project::visibleTo(auth()->user())->find($validated['project_id']);
            if ($targetProject === null) {
                $this->addError('project_id', 'That '.project_term_lower().' is not one you can manage.');

                return null;
            }
            $targetProjects = collect([$targetProject]);
        } else {
            $probe = new SoftwarePolicy(['scope_type' => $this->scope_type, 'scope_id' => $validated['scope_id']]);
            $targetProjectIds = $probe->targetComputers()->pluck('project_id')->unique();
            $targetProjects = Project::withTrashed()->whereIn('id', $targetProjectIds)->get();

            if ($tenantId !== null && $targetProjects->contains(fn (Project $p) => $p->client_id !== $tenantId)) {
                $this->addError('scope_id', 'That target includes machines outside your account.');

                return null;
            }
        }

        // Editing keeps its single package; creating takes the multi-select
        // (with the single package_id as a compatible one-item fallback).
        $ids = $this->policy !== null
            ? [$this->policy->package_id]
            : ($this->packageIds !== [] ? $this->packageIds : array_filter([$this->package_id]));

        if ($ids === []) {
            $this->addError('packageIds', 'Add at least one package with the + button.');

            return null;
        }

        $packages = Package::withoutTrashed()->findMany($ids);
        if ($packages->count() !== count($ids)) {
            $this->addError('packageIds', 'One of the selected packages no longer exists.');

            return null;
        }

        $needsVersion = $versionMode->requiresVersion() || $action === PolicyAction::ForceUpdate;

        // A pinned version belongs to ONE package — pinning five different
        // apps to "24.09" is never what anyone means.
        if ($needsVersion && $packages->count() > 1) {
            $this->addError('version_mode', 'Version pinning works with a single package — select one, or use Latest.');

            return null;
        }

        if ($needsVersion && blank($validated['desired_version'])) {
            $this->addError('desired_version', 'This policy needs a version.');

            return null;
        }
        if (! $versionMode->requiresVersion()) {
            $validated['desired_version'] = $action === PolicyAction::ForceUpdate
                ? $validated['desired_version'] : null;
        }

        // Removal policies have no version dimension.
        if (in_array($action, [PolicyAction::Uninstall, PolicyAction::Block], true)) {
            $validated['version_mode'] = PolicyVersionMode::Latest->value;
            $validated['desired_version'] = null;
        }

        foreach ($packages as $package) {
            // A private package only ever governs its own client's projects.
            // Enforcement's own queue() call refuses this too, per computer —
            // but uncaught, since one already-checked-deployable package is
            // assumed safe by every caller (PolicyService::enforceOn() has
            // its own comment on exactly this: one throw here would abort
            // enforcement for every other computer and policy in the same
            // run). A single-project scope made one check equivalent to
            // that per-computer one; a group or machine can reach several
            // projects at once, so every one of them is checked here, not
            // just the first.
            $unusable = $targetProjects->first(fn (Project $p) => ! $package->isUsableFor($p));
            if ($unusable !== null) {
                $this->addError('packageIds', "\"{$package->name}\" is private to another client and cannot be used here.");

                return null;
            }

            // Version pinning and force update run `winget install --version`.
            if ($needsVersion && $package->winget_id === null) {
                $this->addError('version_mode', 'Version pinning is only available for winget packages.');

                return null;
            }
        }

        // Normalise the scope: a project scope keeps project_id filled (the
        // relation, reports, and every legacy reader still expect it); a
        // group or machine scope has no single project, so it stays null.
        $validated['scope_id'] = $this->scope_type === 'project' ? (int) $validated['project_id'] : (int) $validated['scope_id'];
        $validated['project_id'] = $this->scope_type === 'project' ? $validated['project_id'] : null;

        if ($this->policy) {
            // A new desired version is a new rollout — rings restage from now.
            if ($validated['desired_version'] !== $this->policy->desired_version) {
                $validated['rollout_started_at'] = now();
            }
            $this->policy->update([...$validated, 'package_id' => $this->policy->package_id]);
            session()->flash('status', policy_term().' saved.');

            return $this->redirectRoute('policies.index');
        }

        // One rule per scope+package+action — duplicates would double-queue.
        // In a multi-select, existing rules are skipped and reported, not
        // errors: "make these 10 apps present" should do the 7 that are new.
        $created = 0;
        $skipped = [];
        foreach ($packages as $package) {
            $duplicate = SoftwarePolicy::where('scope_type', $validated['scope_type'])
                ->where('scope_id', $validated['scope_id'])
                ->where('package_id', $package->id)
                ->where('action', $validated['action'])
                ->exists();

            if ($duplicate) {
                $skipped[] = $package->name;

                continue;
            }

            SoftwarePolicy::create([...$validated, 'package_id' => $package->id,
                'created_by'         => auth()->id(),
                'rollout_started_at' => now(),
            ]);
            $created++;
        }

        if ($created === 0) {
            // The single-select fallback reports on its own field, so callers
            // (and long-standing tests) that speak package_id still hear it.
            $field = $this->packageIds === [] ? 'package_id' : 'packageIds';
            $this->addError($field, 'Every selected package already has this policy here: '.implode(', ', $skipped).'.');

            return null;
        }

        $message = $created === 1 ? policy_term().' created.' : "{$created} ".policy_terms_lower().' created.';
        if ($skipped !== []) {
            $message .= ' Skipped (already exist): '.implode(', ', $skipped).'.';
        }
        session()->flash('status', $message.' They apply automatically as agents report in — or use “Enforce now”.');

        return $this->redirectRoute('policies.index');
    }

    public function render()
    {
        $user = auth()->user();

        $selected = Package::findMany($this->packageIds)->sortBy('name')->values();

        $choices = Package::active()->visibleTo($user)
            ->when($this->packageIds !== [], fn ($q) => $q->whereNotIn('packages.id', $this->packageIds))
            ->when(trim($this->packageSearch) !== '', fn ($q) => $q->where('name', 'like', '%'.trim($this->packageSearch).'%'))
            ->orderBy('name')
            ->limit(30)
            ->get(['id', 'name', 'installer_type']);

        return view('livewire.policies.policy-form', [
            'projects'         => Project::visibleTo($user)->orderBy('name')->get(['id', 'name']),
            'groups'           => ComputerGroup::orderBy('name')->get(['id', 'name']),
            'computerChoices'  => $this->scope_type === 'computer'
                ? Computer::visibleTo($user)->orderBy('hostname')->limit(100)->get(['id', 'hostname'])
                : collect(),
            'packages'         => Package::active()->visibleTo($user)->orderBy('name')->get(['id', 'name', 'installer_type']),
            'selectedPackages' => $selected,
            'packageChoices'   => $choices,
            'actions'          => PolicyAction::cases(),
            'modes'            => PolicyMode::cases(),
            'versionModes'     => PolicyVersionMode::cases(),
            'priorities'       => SoftwarePolicy::PRIORITIES,
            'frequencies'      => \App\Enums\PolicyFrequency::cases(),
            'weekdays'         => [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'],
        ])->layout('layouts.app');
    }
}
