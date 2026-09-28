<?php

namespace App\Livewire\Policies;

use App\Enums\PolicyMode;
use App\Livewire\Concerns\WithCompactPagination;
use App\Models\SoftwarePolicy;
use App\Services\PolicyService;
use Livewire\Component;

class PoliciesIndex extends Component
{
    use WithCompactPagination;

    public string $search = '';

    public string $projectFilter = '';

    public function updating($name, $value): void
    {
        if (in_array($name, ['search', 'projectFilter'], true)) {
            $this->resetPage();
        }
    }

    /** Quick toggle between Disabled and Enforce. */
    public function toggle(int $policyId): void
    {
        $policy = SoftwarePolicy::findOrFail($policyId);
        $this->authorize('update', $policy);

        $policy->update(['mode' => $policy->mode === PolicyMode::Disabled
            ? PolicyMode::Enforce->value
            : PolicyMode::Disabled->value]);
    }

    public function enforceNow(int $policyId, PolicyService $service): void
    {
        $policy = SoftwarePolicy::with(['project', 'package'])->findOrFail($policyId);
        $this->authorize('enforce', $policy);

        if ($policy->mode === PolicyMode::Audit) {
            session()->flash('status', "{$policy->label()} is audit-only — it reports compliance but never queues jobs.");

            return;
        }

        $queued = $service->enforce($policy, ignoreWindow: true);

        session()->flash('status', $queued > 0
            ? "{$policy->label()}: {$queued} job(s) queued."
            : "{$policy->label()}: fleet already compliant — nothing to queue.");
    }

    public function delete(int $policyId): void
    {
        $policy = SoftwarePolicy::findOrFail($policyId);
        $this->authorize('delete', $policy);
        $policy->delete();
        session()->flash('status', policy_term().' deleted.');
    }

    public function render(PolicyService $service)
    {
        $this->authorize('viewAny', SoftwarePolicy::class);

        $tenantId = auth()->user()->tenantClientId();

        $policies = SoftwarePolicy::query()
            ->with(['project.client', 'package'])
            ->visibleTo($tenantId)
            // A custom client role's explicit machine list narrows further,
            // same as Computer::visibleTo() — a project-scoped policy still
            // reads project_id (cheap and correct), but group/computer scope
            // needs the actual membership checked.
            ->when($tenantId !== null && auth()->user()->visibleProjectIds() !== null, fn ($q) => $q->where(fn ($w) => $w
                ->whereIn('project_id', auth()->user()->visibleProjectIds())
                ->orWhere('scope_type', '!=', 'project')))
            ->when($this->search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->whereHas('package', fn ($p) => $p->where('name', 'like', "%{$this->search}%"))
                ->orWhereHas('project', fn ($p) => $p->where('name', 'like', "%{$this->search}%"))))
            ->when($this->projectFilter !== '', fn ($q) => $q->where('project_id', $this->projectFilter))
            ->orderBy('priority')
            ->orderBy('id')
            ->paginate(20);

        // Compliance snapshot per visible policy (page of 20 max).
        $summaries = collect($policies->items())
            ->mapWithKeys(fn (SoftwarePolicy $policy) => [
                $policy->id => $policy->isActive() ? $service->complianceSummary($policy) : null,
            ]);

        return view('livewire.policies.policies-index', [
            'policies'  => $policies,
            'summaries' => $summaries,
            'projects'  => \App\Models\Project::orderBy('name')
                ->when($tenantId !== null, fn ($q) => $q->where('client_id', $tenantId)
                    ->when(auth()->user()->visibleProjectIds() !== null,
                        fn ($qq) => $qq->whereIn('id', auth()->user()->visibleProjectIds())))
                ->get(['id', 'name']),
        ])->layout('layouts.app');
    }
}
