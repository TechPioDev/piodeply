<?php

namespace App\Livewire\Computers;

use App\Enums\JobAction;
use App\Enums\JobStatus;
use App\Models\Computer;
use App\Models\DeploymentJob;
use App\Services\PolicyService;
use Livewire\Component;
use Spatie\Activitylog\Models\Activity;

class ComputerShow extends Component
{
    public Computer $computer;

    public string $softwareSearch = '';

    /**
     * deployed  — PioDeploy put it here (the default: what we're accountable for)
     * managed   — matches a catalogue package, however it arrived
     * outdated  — the machine's package manager is offering something newer
     * uptodate  — reported with nothing newer on offer
     * pending   — a job is already queued or running for it
     * all       — everything the machine reported
     */
    public string $softwareFilter = 'deployed';

    public function mount(Computer $computer): void
    {
        $this->authorize('view', $computer);
        $this->computer = $computer->load('project.client');
    }

    /**
     * One-click "Update now" from the software table. If the app is not yet
     * in the catalogue, it is added on the spot (from the winget id the
     * machine reported) and then updated — so an operator never has to leave
     * this page to start managing a piece of software they can see.
     */
    public function queueUpdate(int $softwareId, \App\Services\DeploymentService $deployments): void
    {
        $this->authorize('create', \App\Models\DeploymentJob::class);

        $item = $this->computer->software()->findOrFail($softwareId);

        // Matched on whichever id this source actually adopts under
        // (adoptPackage() below sets winget_id for winget, choco_id for
        // choco) — matching only winget_id made every choco-sourced app
        // fail to find its own catalogue entry and clone a fresh duplicate
        // on every single click, adopted or not. Regardless of active
        // status: an inactive package still occupies its id, so filtering
        // to active() here made a package merely deactivated for editing
        // look like it did not exist yet, and cloned a duplicate too.
        $idColumn = $item->source === 'choco' ? 'choco_id' : 'winget_id';
        $package = \App\Models\Package::usableFor($this->computer->project)
            ->where($idColumn, $item->name)
            ->first();

        // Not in the catalogue yet — add it. Only winget/choco apps can be
        // adopted this way (their id resolves an installer); a bare
        // inventory entry with no manager id cannot be managed.
        if ($package === null) {
            if (! in_array($item->source, ['winget', 'choco'], true)) {
                session()->flash('status', "{$item->name} has no winget/Chocolatey id, so PioDeploy cannot manage its updates. Add it as a package manually.");

                return;
            }

            $package = $this->adoptPackage($item);
            session()->flash('status', "{$item->name} added to your catalogue — updating now.");
        } elseif (! $package->is_active) {
            session()->flash('status', "{$item->name} is in the catalogue but currently deactivated — reactivate it before updating.");

            return;
        }

        $result = $deployments->queueIfNeeded(
            computer: $this->computer,
            package: $package,
            action: \App\Enums\JobAction::Update,
            createdBy: auth()->id(),
        );

        if ($package->wasRecentlyCreated === false) {
            session()->flash('status', $result->message);
        }
    }

    /**
     * Turns a reported software row into a managed catalogue package. Named
     * after what it does — adopts an app the machine already runs into
     * PioDeploy's management. Private to the client whose machine reported
     * it when the operator is a tenant, so one customer's adoption never
     * lands in the shared catalogue.
     */
    private function adoptPackage(\App\Models\ComputerSoftware $item): \App\Models\Package
    {
        $isWinget = $item->source === 'winget';

        $slug = \Illuminate\Support\Str::slug($item->name);
        if (\App\Models\Package::where('slug', $slug)->exists()) {
            $slug .= '-'.\Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(4));
        }

        return \App\Models\Package::create([
            'package_category_id' => \App\Models\PackageCategory::firstOrCreate(
                ['slug' => 'general'], ['name' => 'General', 'sort_order' => 99]
            )->id,
            // A tenant's adoption is private to their own client; staff
            // adopt into the shared catalogue.
            'client_id'      => auth()->user()->tenantClientId(),
            'name'           => $item->name,
            'slug'           => $slug,
            'vendor'         => $item->publisher,
            'installer_type' => $isWinget ? \App\Enums\InstallerType::Winget : \App\Enums\InstallerType::Choco,
            'winget_id'      => $isWinget ? $item->name : null,
            'choco_id'       => $isWinget ? null : $item->name,
            'is_active'      => true,
        ]);
    }

    /**
     * Queues a full agent reinstall: on its next heartbeat the machine
     * re-downloads the current bundle and swaps itself, whatever state its
     * install is in. This is the remote fix for a broken agent that is
     * still checking in — no one touches the machine by hand.
     *
     * Dispatched from the header slot, which renders outside this
     * component's DOM — wire:click there is never heard.
     */
    #[\Livewire\Attributes\On('computer-request-reinstall')]
    public function requestReinstall(): void
    {
        $this->authorize('update', $this->computer);

        $this->computer->forceFill(['reinstall_requested_at' => now()])->save();

        session()->flash('status', 'Reinstall queued — the agent will replace itself at its next check-in (within ~1 minute while online).');
    }

    /**
     * Queues agent removal. The machine uninstalls its own agent at the next
     * heartbeat: service deleted, files removed. The computer record stays
     * (with its history) until someone deletes it here.
     */
    #[\Livewire\Attributes\On('computer-request-uninstall')]
    public function requestUninstall(): void
    {
        $this->authorize('delete', $this->computer);

        $this->computer->forceFill(['uninstall_requested_at' => now()])->save();

        session()->flash('status', 'Uninstall queued — the agent will remove itself from the machine at its next check-in. The computer record and its history remain until you delete them.');
    }

    /** Withdraws a queued command the agent has not collected yet. */
    #[\Livewire\Attributes\On('computer-cancel-agent-command')]
    public function cancelAgentCommand(): void
    {
        $this->authorize('update', $this->computer);

        $this->computer->forceFill([
            'reinstall_requested_at' => null,
            'uninstall_requested_at' => null,
        ])->save();

        session()->flash('status', 'Pending agent command cancelled.');
    }

    /**
     * MSP-style health checks derived from inventory + heartbeat data.
     *
     * @return list<array{level: string, message: string}>
     */
    public function healthChecks(): array
    {
        $checks = [];
        $computer = $this->computer;

        if ($computer->last_seen_at === null) {
            $checks[] = ['level' => 'warn', 'message' => 'Agent has never reported in — verify the agent service is installed and running.'];
        } elseif ($computer->last_seen_at->lt(now()->subDay())) {
            $checks[] = ['level' => 'warn', 'message' => 'Offline for ' . $computer->last_seen_at->diffForHumans(null, true) . ' — machine may be decommissioned or the agent stopped.'];
        }

        if ($computer->disk_total_bytes && $computer->disk_free_bytes !== null) {
            $freePercent = $computer->disk_free_bytes / $computer->disk_total_bytes * 100;
            if ($freePercent < 10) {
                $checks[] = ['level' => 'warn', 'message' => 'Low disk space: only ' . Computer::bytesForHumans($computer->disk_free_bytes) . ' (' . round($freePercent) . '%) free on the system drive.'];
            }
        }

        if ($computer->secure_boot === false) {
            $checks[] = ['level' => 'warn', 'message' => 'Secure Boot is disabled.'];
        }

        if ($computer->tpm_enabled === false) {
            $checks[] = ['level' => 'warn', 'message' => 'TPM is disabled.'];
        } elseif ($computer->tpm_enabled === null) {
            $checks[] = ['level' => 'info', 'message' => 'TPM state unknown — the agent runs unelevated; install as a service to read it.'];
        }

        $failed = DeploymentJob::where('computer_id', $computer->id)->where('status', JobStatus::Failed)->count();
        if ($failed > 0) {
            $checks[] = ['level' => 'warn', 'message' => "{$failed} failed deployment " . str('job')->plural($failed) . ' need attention.'];
        }

        return $checks;
    }

    public function render()
    {
        $jobs = DeploymentJob::where('computer_id', $this->computer->id);

        $diskUsedPercent = null;
        if ($this->computer->disk_total_bytes && $this->computer->disk_free_bytes !== null) {
            $diskUsedPercent = round(
                ($this->computer->disk_total_bytes - $this->computer->disk_free_bytes)
                / $this->computer->disk_total_bytes * 100
            );
        }

        // Exact winget-id match -> the software is a managed catalogue package.
        $managedPackages = \App\Models\Package::whereNotNull('winget_id')->pluck('id', 'winget_id');

        // "PioDeploy put this here" = we have a succeeded install/update for
        // the package on this machine. Anything else that is merely in the
        // catalogue arrived some other way, which is the distinction an MSP
        // wants when auditing what it introduced to a client's estate.
        $deployedPackageIds = DeploymentJob::where('computer_id', $this->computer->id)
            ->whereIn('action', [JobAction::Install, JobAction::Update])
            ->where('status', JobStatus::Succeeded)
            ->distinct()
            ->pluck('package_id');

        $deployedNames = $managedPackages
            ->filter(fn (int $packageId) => $deployedPackageIds->contains($packageId))
            ->keys();

        // winget id -> the current in-flight action for that package on this
        // machine, so the Status column can say "Queued" or "Updating…" the
        // instant a job is queued, instead of the operator wondering whether
        // the click landed.
        $inFlightByWingetId = DeploymentJob::query()
            ->where('computer_id', $this->computer->id)
            ->whereIn('status', [JobStatus::Pending, JobStatus::Blocked, JobStatus::Running])
            ->whereIn('package_id', $managedPackages->values())
            ->get(['package_id', 'status'])
            ->keyBy('package_id')
            // Flip package_id -> winget_id so the blade can look it up by the
            // software row's name.
            ->mapWithKeys(fn ($job) => [$managedPackages->search($job->package_id) => $job->status]);

        // Fleet-relative, never against Microsoft's release feed — see
        // BrowserVersionService. Empty for a machine with no tracked
        // browser installed; the view treats that as nothing to show.
        $browsers = app(\App\Services\BrowserVersionService::class)->summaryFor(
            $this->computer,
            app(\App\Services\BrowserVersionService::class)->fleetLatestForClient($this->computer->project->client_id)
        );

        return view('livewire.computers.computer-show', [
            'health' => $this->healthChecks(),
            'browsers' => $browsers,
            'readinessIssues' => app(\App\Services\ReadinessService::class)->issues($this->computer),
            'stats'  => [
                'succeeded'   => (clone $jobs)->where('status', JobStatus::Succeeded)->count(),
                'in_flight'   => (clone $jobs)->whereIn('status', [JobStatus::Pending, JobStatus::Blocked, JobStatus::Running])->count(),
                'failed'      => (clone $jobs)->where('status', JobStatus::Failed)->count(),
                'last_deploy' => (clone $jobs)->where('status', JobStatus::Succeeded)->max('finished_at'),
            ],
            // One row per task, newest first — a package deployed hourly is
            // one line with a count, not eight identical ones.
            'recentJobs' => DeploymentJob::with(['package', 'packageVersion'])
                ->where('computer_id', $this->computer->id)
                ->withRepeatCount()
                ->onlyLatestPerTask()
                ->orderByDesc('id')->limit(8)->get(),
            // Why each policy is or is not acting here — the answer to
            // "so why isn't it installed?" when there is no job to point at.
            'policyExplanations' => app(PolicyService::class)->explainFor($this->computer),
            'jobLog' => DeploymentJob::with(['package'])
                ->where('computer_id', $this->computer->id)
                ->orderByDesc('id')
                ->limit(30)
                ->get(),
            'recentActivity' => Activity::where('subject_type', Computer::class)
                ->where('subject_id', $this->computer->id)
                ->latest()->limit(5)->get(),
            'diskUsedPercent' => $diskUsedPercent,
            'softwareTotal'   => $this->computer->software()->count(),
            'softwareManaged' => $this->computer->software()
                ->where('source', 'winget')->whereIn('name', $managedPackages->keys())->count(),
            'softwareDeployed' => $this->computer->software()
                ->where('source', 'winget')->whereIn('name', $deployedNames)->count(),
            // Counted in PHP, not SQL: "newer" is a version comparison, and
            // winget sometimes offers an "available" that is not ahead.
            'softwareOutdated' => $this->computer->software()
                ->withUpdateAvailable()->get()->filter->hasUpdate()->count(),
            // Total minus outdated, not a second query: both numbers must
            // always add up to the total the card row sits next to.
            'softwareUpToDate' => $this->computer->software()->count()
                - $this->computer->software()->withUpdateAvailable()->get()->filter->hasUpdate()->count(),
            'softwarePending' => $this->computer->software()
                ->where('source', 'winget')->whereIn('name', $inFlightByWingetId->keys())->count(),
            'softwareItems'   => $this->computer->software()
                ->when($this->softwareFilter === 'managed', fn ($q) => $q
                    ->where('source', 'winget')
                    ->whereIn('name', $managedPackages->keys()))
                ->when($this->softwareFilter === 'deployed', fn ($q) => $q
                    ->where('source', 'winget')
                    ->whereIn('name', $deployedNames))
                ->when($this->softwareFilter === 'outdated', fn ($q) => $q->withUpdateAvailable())
                ->when($this->softwareFilter === 'uptodate', fn ($q) => $q->whereNull('available_version'))
                ->when($this->softwareFilter === 'pending', fn ($q) => $q
                    ->where('source', 'winget')
                    ->whereIn('name', $inFlightByWingetId->keys()))
                ->when($this->softwareSearch !== '', fn ($q) => $q->where(fn ($w) => $w
                    ->where('name', 'like', "%{$this->softwareSearch}%")
                    ->orWhere('publisher', 'like', "%{$this->softwareSearch}%")))
                ->orderBy('name')
                ->limit(150)
                ->get(),
            'managedPackages' => $managedPackages,
            'deployedNames'   => $deployedNames,
            'inFlightByWingetId' => $inFlightByWingetId,
            'browserPolicyRows' => \App\Models\BrowserPolicy::query()
                ->where('project_id', $this->computer->project_id)
                ->where('status', 'active')
                ->with(['results' => fn ($q) => $q->where('computer_id', $this->computer->id)])
                ->get()
                ->map(fn ($policy) => [
                    'policy'   => $policy,
                    'excluded' => $policy->excludedComputers()->whereKey($this->computer->id)->exists(),
                    'results'  => $policy->results->keyBy('browser'),
                ]),
        ])->layout('layouts.app');
    }
}
