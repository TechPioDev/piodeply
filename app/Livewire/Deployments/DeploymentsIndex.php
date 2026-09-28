<?php

namespace App\Livewire\Deployments;

use App\Models\DeploymentJob;
use App\Services\DeploymentService;
use Livewire\Attributes\Url;
use Livewire\Component;
use App\Livewire\Concerns\WithCompactPagination;

class DeploymentsIndex extends Component
{
    use WithCompactPagination;

    public string $search = '';

    // Bound to the URL so the dashboard's "Failed deployments" health-issue
    // row can deep-link straight to the filtered list (?status=failed).
    #[Url]
    public string $status = '';

    public string $action = '';

    /**
     * Off (the default) shows the current job per computer+package+action,
     * so a package deployed ten times is one row, not ten. On shows every
     * attempt — the full audit trail is never deleted, only folded.
     */
    public bool $history = false;

    public function updating($name, $value): void
    {
        if (in_array($name, ['search', 'status', 'action', 'history'], true)) {
            $this->resetPage();
        }
    }

    public function retry(int $jobId, DeploymentService $service): void
    {
        $job = DeploymentJob::findOrFail($jobId);
        $this->authorize('manage', $job);

        // Some failures are in the job, not the machine. Re-running those just
        // spends three more attempts arriving at the same place.
        if ($reason = $job->impossibleReason()) {
            session()->flash('status', $reason);

            return;
        }

        $service->retry($job);
    }

    /**
     * Requeue every failed job this user can see. After a fix lands — an
     * agent update, a corrected package, a machine brought back — the
     * fleet's red rows are one decision, not fifty clicks. Jobs whose
     * failure is in the job itself (a rollback with no target version) are
     * skipped and counted rather than sent round the same loop again.
     *
     * Dispatched from the header slot, which renders outside this
     * component's DOM — wire:click there is never heard.
     */
    #[\Livewire\Attributes\On('deployments-retry-all-failed')]
    public function retryAllFailed(DeploymentService $service): void
    {
        $this->authorize('create', DeploymentJob::class);

        $requeued = $skipped = 0;

        // Chunked: a fleet-wide failure can be thousands of rows, and the
        // page must not hold them all in memory to fix them.
        $this->scopedJobs()
            ->where('status', \App\Enums\JobStatus::Failed)
            ->chunkById(200, function ($jobs) use ($service, &$requeued, &$skipped) {
                foreach ($jobs as $job) {
                    if ($job->impossibleReason() !== null) {
                        $skipped++;

                        continue;
                    }

                    $service->retry($job);
                    $requeued++;
                }
            });

        activity('deployments')->causedBy(auth()->user())
            ->withProperties(['requeued' => $requeued, 'skipped' => $skipped])
            ->log('retried_all_failed');

        session()->flash('status', $requeued === 0 && $skipped === 0
            ? 'Nothing to retry — no failed deployments.'
            : "{$requeued} failed ".str('deployment')->plural($requeued).' requeued'
                .($skipped > 0 ? ", {$skipped} skipped (they cannot succeed as queued)" : '')
                .'. Agents pick them up at their next check-in.');
    }

    public function cancel(int $jobId, DeploymentService $service): void
    {
        $job = DeploymentJob::findOrFail($jobId);
        $this->authorize('manage', $job);
        $service->cancel($job);
    }

    /**
     * The job set this user may act on — the same tenancy and per-project
     * confinement the list uses, so a bulk action can never reach further
     * than the eye can see.
     */
    private function scopedJobs()
    {
        $tenantId = auth()->user()->tenantClientId();

        return DeploymentJob::query()
            ->when($tenantId !== null, fn ($q) => $q->whereHas(
                'computer.project',
                fn ($p) => $p->withTrashed()->where('client_id', $tenantId)
                    ->when(auth()->user()->visibleProjectIds() !== null,
                        fn ($qq) => $qq->whereIn('projects.id', auth()->user()->visibleProjectIds()))
            ));
    }

    /**
     * The current-state view every card and the unfiltered table agree on:
     * tenant-scoped, folded to one row per task unless history is on. Never
     * narrowed by search/status/action — those describe what the table
     * shows, not what the fleet's queue actually looks like right now.
     */
    private function currentStateJobs()
    {
        $tenantId = auth()->user()->tenantClientId();

        return DeploymentJob::query()
            ->unless($this->history, fn ($q) => $q->onlyLatestPerTask())
            ->when($tenantId !== null, fn ($q) => $q->whereHas(
                'computer.project',
                fn ($p) => $p->withTrashed()->where('client_id', $tenantId)
                    ->when(auth()->user()->visibleProjectIds() !== null,
                        fn ($qq) => $qq->whereIn('projects.id', auth()->user()->visibleProjectIds()))
            ));
    }

    public function render()
    {
        $this->authorize('viewAny', DeploymentJob::class);

        $base = $this->currentStateJobs();
        $stats = [
            'total'     => (clone $base)->count(),
            'succeeded' => (clone $base)->where('status', \App\Enums\JobStatus::Succeeded)->count(),
            'failed'    => (clone $base)->where('status', \App\Enums\JobStatus::Failed)->count(),
            'in_flight' => (clone $base)->whereIn('status', [
                \App\Enums\JobStatus::Pending, \App\Enums\JobStatus::Blocked, \App\Enums\JobStatus::Running,
            ])->count(),
        ];

        $jobs = (clone $base)
            ->with(['computer', 'package', 'packageVersion'])
            ->withRepeatCount()
            // The two search branches must be grouped: ungrouped, AND binds
            // tighter than OR and the package branch escapes the tenancy
            // filter above, showing a client another client's machines.
            ->when($this->search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->whereHas('computer', fn ($c) => $c->where('hostname', 'like', "%{$this->search}%"))
                ->orWhereHas('package', fn ($p) => $p->where('name', 'like', "%{$this->search}%"))))
            ->when($this->status === 'in_flight', fn ($q) => $q->whereIn('status', [
                \App\Enums\JobStatus::Pending, \App\Enums\JobStatus::Blocked, \App\Enums\JobStatus::Running,
            ]))
            ->when($this->status !== '' && $this->status !== 'in_flight', fn ($q) => $q->where('status', $this->status))
            ->when($this->action !== '', fn ($q) => $q->where('action', $this->action))
            ->orderByDesc('id')
            ->paginate(20);

        return view('livewire.deployments.deployments-index', [
            'jobs'     => $jobs,
            'stats'    => $stats,
            'statuses' => \App\Enums\JobStatus::cases(),
            'actions'  => \App\Enums\JobAction::cases(),
            // Drives the "Retry all failed" button — counted over every
            // failed ROW this user may act on (not folded: retrying touches
            // actual job rows), so the number and the action always agree.
            // Deliberately a separate figure from the Failed card above,
            // which counts currently-failing TASKS to match the table.
            'failedCount' => $this->scopedJobs()->where('status', \App\Enums\JobStatus::Failed)->count(),
        ])->layout('layouts.app');
    }
}
