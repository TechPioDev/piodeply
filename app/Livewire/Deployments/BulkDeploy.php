<?php

namespace App\Livewire\Deployments;

use App\Enums\DeploymentRing;
use App\Enums\JobAction;
use App\Models\Computer;
use App\Models\ComputerGroup;
use App\Models\DeploymentJob;
use App\Models\Package;
use App\Models\Project;
use App\Services\DeploymentService;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * Queue one package/action across a target — a whole project ("Site"), a
 * device group, or a hand-picked list of machines — optionally narrowed to
 * a deployment ring. A one-off fan-out — policies remain the tool for
 * ongoing desired state.
 */
class BulkDeploy extends Component
{
    /** 'site' | 'group' | 'machines' */
    public string $targetType = 'site';

    public ?int $projectId = null;

    public ?int $groupId = null;

    /** Explicit target: creation only, mirrors PolicyForm's package picker. @var list<int> */
    public array $machineIds = [];

    public string $machineSearch = '';

    public ?int $packageId = null;

    public string $action = 'install';

    public int $priority = 5;

    /** Optional pinned version (winget/choco `--version`). */
    public ?string $targetVersion = null;

    /**
     * '' = every ring, else a specific DeploymentRing value. Meaningless
     * once specific machines are hand-picked — the picker itself is the
     * targeting decision, so the field is hidden and ignored there.
     */
    public string $ring = '';

    /** Deploy even where the machine already satisfies the request. */
    public bool $force = false;

    public function mount(): void
    {
        $this->authorize('create', DeploymentJob::class);
    }

    /** Switching kind invalidates whatever the previous kind had picked. */
    public function updatedTargetType(): void
    {
        $this->projectId = null;
        $this->groupId = null;
        $this->machineIds = [];
        $this->machineSearch = '';
        $this->ring = '';
    }

    public function addMachine(int $computerId): void
    {
        $exists = Computer::visibleTo(auth()->user())->whereKey($computerId)->exists();

        if ($exists && ! in_array($computerId, $this->machineIds, true)) {
            $this->machineIds[] = $computerId;
        }

        $this->machineSearch = '';
    }

    public function removeMachine(int $computerId): void
    {
        $this->machineIds = array_values(array_diff($this->machineIds, [$computerId]));
    }

    /** Drop an action the newly chosen package can't perform. */
    public function updatedPackageId(): void
    {
        $this->targetVersion = null;

        $package = $this->packageId !== null ? Package::active()->find($this->packageId) : null;
        $action = JobAction::tryFrom($this->action);

        if ($package !== null && $action !== null && ! $this->offersAction($package, $action)) {
            $this->action = JobAction::Install->value;
        }
    }

    public function queue(DeploymentService $service): void
    {
        $this->authorize('create', DeploymentJob::class);

        $validated = $this->validate([
            'targetType'    => ['required', Rule::in(['site', 'group', 'machines'])],
            'projectId'     => ['required_if:targetType,site', 'nullable', 'integer', Rule::exists('projects', 'id')],
            'groupId'       => ['required_if:targetType,group', 'nullable', 'integer', Rule::exists('computer_groups', 'id')],
            'machineIds'    => ['required_if:targetType,machines', 'array'],
            'machineIds.*'  => ['integer', Rule::exists('computers', 'id')],
            'packageId'     => ['required', 'integer', Rule::exists('packages', 'id')->where('is_active', true)],
            'action'        => ['required', Rule::in(JobAction::values())],
            'priority'      => ['required', 'integer', 'between:1,10'],
            'ring'          => ['nullable', Rule::in(DeploymentRing::values())],
            'targetVersion' => ['nullable', 'string', 'max:100'],
        ]);

        $package = Package::findOrFail($validated['packageId']);

        if ($this->targetType === 'site') {
            $project = $this->scopedProjects()->findOrFail($validated['projectId']);

            // A private package only deploys to its own client's machines.
            // A single project makes this one check equivalent to the
            // per-machine one queueBulk() runs anyway — worth failing here,
            // before anything is queued, with a specific reason. A group or
            // an explicit machine list can span clients, so that upfront
            // shortcut no longer applies there; queueBulk()'s own per-machine
            // check still refuses each one and the summary reports it.
            if (! $package->isUsableFor($project)) {
                $this->addError('packageId', "\"{$package->name}\" is private to another client and cannot be deployed to this project.");

                return;
            }

            $computers = Computer::where('project_id', $project->id)
                ->when($this->ring !== '', fn ($q) => $q->where('ring', $this->ring))
                ->get();
        } elseif ($this->targetType === 'group') {
            $computers = Computer::visibleTo(auth()->user())
                ->whereHas('groups', fn ($q) => $q->whereKey($validated['groupId']))
                ->when($this->ring !== '', fn ($q) => $q->where('ring', $this->ring))
                ->get();
        } else {
            // Hand-picked machines: visibleTo() re-checked here (not just at
            // add-time) so a since-revoked machine can never be targeted by
            // replaying old component state.
            $computers = Computer::visibleTo(auth()->user())
                ->whereIn('id', $validated['machineIds'])
                ->get();
        }

        $result = $service->queueBulk(
            computers: $computers,
            package: $package,
            action: JobAction::from($validated['action']),
            priority: $validated['priority'],
            createdBy: auth()->id(),
            targetVersion: $this->targetVersion !== null && trim($this->targetVersion) !== '' ? trim($this->targetVersion) : null,
            force: $this->force,
        );

        $this->dispatch('job-queued');
        session()->flash('status', $result->summary());
    }

    public function render()
    {
        $package = $this->packageId !== null ? Package::active()->find($this->packageId) : null;

        $selectedMachines = $this->machineIds !== []
            ? Computer::visibleTo(auth()->user())->whereIn('id', $this->machineIds)->orderBy('hostname')->get(['id', 'hostname'])
            : collect();

        $machineChoices = Computer::visibleTo(auth()->user())
            ->when($this->machineIds !== [], fn ($q) => $q->whereNotIn('computers.id', $this->machineIds))
            ->when(trim($this->machineSearch) !== '', fn ($q) => $q->where('hostname', 'like', '%'.trim($this->machineSearch).'%'))
            ->orderBy('hostname')
            ->limit(30)
            ->get(['id', 'hostname']);

        return view('livewire.deployments.bulk-deploy', [
            'projects'    => $this->scopedProjects()->orderBy('name')->get(['id', 'name', 'client_id']),
            'groups'      => ComputerGroup::orderBy('name')->get(['id', 'name']),
            'selectedMachines' => $selectedMachines,
            'machineChoices'   => $machineChoices,
            'packages'    => Package::active()->deployableBy(auth()->user())->orderBy('name')->get(['id', 'name', 'installer_type']),
            'rings'       => DeploymentRing::cases(),
            // Bulk covers install/update/repair/remove — rollback stays a
            // per-machine action (each machine's previous version differs).
            'actions'     => collect(JobAction::cases())
                ->reject(fn (JobAction $a) => $a === JobAction::Rollback)
                ->filter(fn (JobAction $a) => $package === null || $package->installer_type->supports($a))
                ->values()->all(),
            'versionKnown' => $package?->installer_type->requiresPackageManagerId() ?? false,
            'targetCount'  => $this->targetCount(),
        ])->layout('layouts.app');
    }

    /** Projects the current user may target (tenant users see only their own). */
    private function scopedProjects()
    {
        $tenantId = auth()->user()->tenantClientId();

        return Project::query()
            ->when($tenantId !== null, fn ($q) => $q->where('client_id', $tenantId))
            ->when(auth()->user()->visibleProjectIds() !== null,
                fn ($q) => $q->whereIn('id', auth()->user()->visibleProjectIds()));
    }

    private function offersAction(Package $package, JobAction $action): bool
    {
        return $action !== JobAction::Rollback && $package->installer_type->supports($action);
    }

    private function targetCount(): int
    {
        if ($this->targetType === 'site') {
            if ($this->projectId === null) {
                return 0;
            }

            $project = $this->scopedProjects()->find($this->projectId);

            if ($project === null) {
                return 0;
            }

            return Computer::where('project_id', $project->id)
                ->when($this->ring !== '', fn ($q) => $q->where('ring', $this->ring))
                ->count();
        }

        if ($this->targetType === 'group') {
            if ($this->groupId === null) {
                return 0;
            }

            return Computer::visibleTo(auth()->user())
                ->whereHas('groups', fn ($q) => $q->whereKey($this->groupId))
                ->when($this->ring !== '', fn ($q) => $q->where('ring', $this->ring))
                ->count();
        }

        return count($this->machineIds);
    }
}
