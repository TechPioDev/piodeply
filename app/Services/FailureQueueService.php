<?php

namespace App\Services;

use App\Enums\JobStatus;
use App\Models\Computer;
use App\Models\DeploymentFailureDismissal;
use App\Models\DeploymentJob;
use App\Models\DeploymentRequest;
use App\Models\SoftwarePolicy;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The "Needs attention" queue: every failure cause that is still live,
 * grouped exactly the way escalate() groups them for notifications, so the
 * portal and the inbox never disagree about what "one problem" means.
 *
 * A cause leaves the queue two ways: automatically, when a later job for the
 * same scope succeeds (the fix already worked, nobody needs to click
 * anything); or manually, when an operator marks it handled. A dismissal is
 * tied to the failure that existed at the time — a fresh failure of the same
 * cause has a higher job id and reappears rather than staying silenced.
 */
class FailureQueueService
{
    /** Bounds a pathological backlog; ordinary fleets never approach this. */
    private const MAX_SCANNED = 2000;

    /**
     * @return Collection<int, array{
     *   cause_key: string, kind: \App\Enums\FailureKind, owner: string,
     *   package: ?\App\Models\Package, computer: ?\App\Models\Computer,
     *   exit_code: ?int, failure_reason: ?string, hint: ?string,
     *   affected_computers: int, first_seen: \Illuminate\Support\Carbon,
     *   last_seen: \Illuminate\Support\Carbon, latest_job: DeploymentJob,
     * }>
     */
    public function unresolvedCauses(User $viewer): Collection
    {
        $allowed = $viewer->visibleProjectIds();
        $tenantId = $viewer->tenantClientId();

        $failures = DeploymentJob::query()
            ->where('status', JobStatus::Failed)
            ->whereHas('computer', function ($q) use ($allowed, $tenantId) {
                // visibleProjectIds() alone is not the tenant boundary: it
                // comes back null both for unconfined STAFF and for a tenant
                // with no site-scoped overlay. The client_id check is what
                // actually keeps one tenant off another's failures.
                if ($allowed !== null) {
                    $q->whereIn('project_id', $allowed);
                }
                if ($tenantId !== null) {
                    $q->whereHas('project', fn ($p) => $p->where('client_id', $tenantId));
                }
            })
            ->with(['package', 'computer.project.client'])
            ->latest('id')
            ->limit(self::MAX_SCANNED)
            ->get();

        return $failures
            ->groupBy(fn (DeploymentJob $job) => $job->causeKey())
            ->map(fn (Collection $group) => $this->summarise($group))
            ->reject(fn (array $cause) => $this->isResolved($cause))
            ->sortByDesc(fn (array $cause) => $cause['last_seen'])
            ->values();
    }

    /**
     * The full triage board: deployment failures (above) plus three more
     * signal sources the client's own review asked for — offline machines,
     * policies with failing machines, and approvals stuck waiting on an
     * owner — normalised into one shape so the page can show Machine, Site,
     * Issue, Severity, Detection time, Status, and a Recommended action for
     * every row alike, whatever kind of problem it actually is.
     *
     * Each new source resolves itself the same way deployment failures do —
     * no separate "mark handled" flow for them, since acting on the actual
     * thing (the machine checks back in, the policy exclusion or fix lands,
     * the owner decides the request) is what makes them disappear. Adding a
     * parallel dismissal path for causes with no DeploymentJob behind them
     * would mean extending DeploymentFailureDismissal's schema to key on
     * something that isn't a job id — a bigger, separate change, not
     * something to fold in silently here.
     *
     * @return Collection<int, array{
     *   type: string, severity: string, issue: string, computer: ?Computer,
     *   site: ?string, detected_at: \Illuminate\Support\Carbon, status: string,
     *   recommended_action: string, action_url: ?string, action_label: ?string,
     *   dismissable: bool, cause_key: ?string, latest_job: ?DeploymentJob,
     * }>
     */
    public function attentionItems(User $viewer): Collection
    {
        return $this->unresolvedCauses($viewer)->map(fn (array $cause) => $this->fromDeploymentFailure($cause))
            ->concat($this->offlineComputers($viewer))
            ->concat($this->failedPolicies($viewer))
            ->concat($this->pendingApprovals($viewer))
            ->sortBy([
                fn (array $a, array $b) => $this->severityRank($a['severity']) <=> $this->severityRank($b['severity']),
                fn (array $a, array $b) => $b['detected_at'] <=> $a['detected_at'],
            ])
            ->values();
    }

    private function severityRank(string $severity): int
    {
        return match ($severity) {
            'Critical' => 0,
            'Warning'  => 1,
            default    => 2, // Info
        };
    }

    private function fromDeploymentFailure(array $cause): array
    {
        /** @var DeploymentJob $latest */
        $latest = $cause['latest_job'];

        return [
            'type'               => 'deployment_failure',
            'severity'           => $cause['kind']->ownedByOperator() ? 'Critical' : 'Warning',
            'issue'              => ($cause['package']?->name ?? 'Unknown package').' failed to deploy',
            'computer'           => $cause['computer'],
            'site'               => $latest->computer?->project?->name,
            'detected_at'        => $cause['first_seen'],
            'status'             => 'Failed ('.$cause['affected_computers'].' '.\Illuminate\Support\Str::plural('machine', $cause['affected_computers']).')',
            'recommended_action' => $cause['hint'] ?? ($cause['failure_reason'] ?? 'Review the exit code and retry.'),
            'action_url'         => $cause['computer'] ? route('computers.show', $cause['computer']) : ($cause['package'] ? route('packages.show', $cause['package']) : null),
            'action_label'       => 'Investigate',
            'dismissable'        => true,
            'cause_key'          => $cause['cause_key'],
            'latest_job'         => $latest,
        ];
    }

    /**
     * Reuses the exact same offline_notified_at flag CheckOfflineAgents
     * already raises (and ComputerService clears the moment a machine
     * checks back in) — so the portal and the outbound "agent offline"
     * alert agree on what counts as offline, instead of the page inventing
     * its own separate threshold.
     */
    private function offlineComputers(User $viewer): Collection
    {
        $allowed = $viewer->visibleProjectIds();
        $tenantId = $viewer->tenantClientId();

        return Computer::query()
            ->whereNotNull('offline_notified_at')
            ->when($allowed !== null, fn ($q) => $q->whereIn('project_id', $allowed))
            ->when($tenantId !== null, fn ($q) => $q->whereHas('project', fn ($p) => $p->where('client_id', $tenantId)))
            ->with('project')
            ->get()
            ->map(fn (Computer $computer) => [
                'type'               => 'offline',
                'severity'           => 'Warning',
                'issue'              => 'Not checking in',
                'computer'           => $computer,
                'site'               => $computer->project?->name,
                'detected_at'        => $computer->offline_notified_at,
                'status'             => 'Offline since '.$computer->last_seen_at?->diffForHumans(),
                'recommended_action' => 'Confirm the machine is powered on and network-reachable, and that the agent service is running.',
                'action_url'         => route('computers.show', $computer),
                'action_label'       => 'View machine',
                'dismissable'        => false,
                'cause_key'          => null,
                'latest_job'         => null,
            ]);
    }

    /**
     * One row per policy with at least one machine failing, not one row per
     * machine — the same "one problem, not one row per computer" rule
     * unresolvedCauses() already applies to deployment failures.
     */
    private function failedPolicies(User $viewer): Collection
    {
        $tenantId = $viewer->tenantClientId();
        $policyService = app(PolicyService::class);

        return SoftwarePolicy::with(['package', 'project'])
            ->where('mode', \App\Enums\PolicyMode::Enforce)
            ->visibleTo($tenantId)
            ->get()
            ->map(function (SoftwarePolicy $policy) use ($policyService) {
                $summary = $policyService->complianceSummary($policy);

                return $summary['failed'] > 0 ? [$policy, $summary] : null;
            })
            ->filter()
            ->map(fn (array $pair) => [
                'type'               => 'policy_failed',
                'severity'           => 'Warning',
                'issue'              => $pair[0]->label().' is failing',
                'computer'           => null,
                'site'               => $pair[0]->scopeName(),
                'detected_at'        => $pair[0]->last_enforced_at ?? $pair[0]->updated_at,
                'status'             => $pair[1]['failed'].' '.\Illuminate\Support\Str::plural('machine', $pair[1]['failed']).' failing',
                'recommended_action' => 'Open the '.policy_term_lower().' to see which machines and why, then retry or exclude them.',
                'action_url'         => route('policies.show', $pair[0]),
                'action_label'       => 'Open '.policy_term_lower(),
                'dismissable'        => false,
                'cause_key'          => null,
                'latest_job'         => null,
            ]);
    }

    /**
     * Only the account owner can act on these (the same restriction the
     * Approvals page itself enforces), so this is the one source that
     * contributes nothing for platform staff or a non-owner tenant user —
     * showing an item nobody viewing the page can act on would fail the
     * client's own "technicians can take action directly" criterion.
     */
    private function pendingApprovals(User $viewer): Collection
    {
        if (! $viewer->isClientOwner()) {
            return collect();
        }

        return DeploymentRequest::with(['computer.project', 'package', 'requester'])
            ->where('client_id', $viewer->tenantClientId())
            ->where('status', 'pending')
            ->get()
            ->map(fn (DeploymentRequest $request) => [
                'type'               => 'pending_approval',
                'severity'           => 'Info',
                'issue'              => ($request->requester?->name ?? 'Someone').' requested '.($request->package?->name ?? 'a package').' on '.($request->computer?->hostname ?? 'a machine'),
                'computer'           => $request->computer,
                'site'               => $request->computer?->project?->name,
                'detected_at'        => $request->created_at,
                'status'             => 'Awaiting your decision',
                'recommended_action' => 'Approve or reject the request.',
                'action_url'         => route('approvals.index'),
                'action_label'       => 'Review',
                'dismissable'        => false,
                'cause_key'          => null,
                'latest_job'         => null,
            ]);
    }

    /** @param  Collection<int, DeploymentJob>  $group */
    private function summarise(Collection $group): array
    {
        /** @var DeploymentJob $latest */
        $latest = $group->sortByDesc('id')->first();
        $kind = $latest->failureKind();

        return [
            'cause_key'          => $latest->causeKey(),
            'kind'               => $kind,
            'owner'              => $kind->ownedByOperator()
                ? 'Platform administrator'
                : 'Whoever manages this machine',
            'package'            => $latest->package,
            // Only a machine-scoped cause names one machine; a package cause
            // may span several, so naming a single one here would mislead.
            'computer'           => $kind === \App\Enums\FailureKind::Machine ? $latest->computer : null,
            'exit_code'          => $latest->exit_code,
            'failure_reason'     => $latest->failure_reason,
            'hint'               => $latest->failureHint(),
            'affected_computers' => $group->pluck('computer_id')->unique()->count(),
            'first_seen'         => $group->min('created_at'),
            'last_seen'          => $group->max(fn (DeploymentJob $j) => $j->finished_at ?? $j->created_at),
            'latest_job'         => $latest,
        ];
    }

    private function isResolved(array $cause): bool
    {
        return $this->clearedBySuccess($cause) || $this->dismissed($cause);
    }

    /** Did the same scope succeed after this failure was recorded? */
    private function clearedBySuccess(array $cause): bool
    {
        /** @var DeploymentJob $latest */
        $latest = $cause['latest_job'];
        $boundary = $latest->finished_at ?? $latest->created_at;

        $scoped = $cause['kind'] === \App\Enums\FailureKind::Machine
            ? DeploymentJob::where('computer_id', $latest->computer_id)
            : DeploymentJob::where('package_id', $latest->package_id);

        return $scoped->where('status', JobStatus::Succeeded)
            ->where('finished_at', '>', $boundary)
            ->exists();
    }

    private function dismissed(array $cause): bool
    {
        $dismissal = DeploymentFailureDismissal::where('cause_key', $cause['cause_key'])->first();

        return $dismissal !== null && $dismissal->last_seen_job_id >= $cause['latest_job']->id;
    }

    /**
     * An operator has looked at this and dealt with it.
     *
     * Dismissal is stored once per cause, not once per viewer, so who may
     * dismiss matters more than it would for a purely personal "mark read":
     * silencing a PACKAGE cause hides it from every tenant it affects, not
     * just the person who clicked. Package/unknown causes are therefore
     * staff-only; a machine cause may be cleared by whoever manages that
     * one machine, matching the ownership already stated on the queue.
     */
    public function markHandled(string $causeKey, DeploymentJob $latestJob, User $by): void
    {
        $kind = $latestJob->failureKind();

        if ($kind->ownedByOperator()) {
            abort_unless($by->tenantClientId() === null, 403, 'Only platform staff can dismiss a package-level failure.');
        } else {
            abort_unless(
                $by->tenantClientId() === null || $by->tenantClientId() === $latestJob->computer->project->client_id,
                403
            );
        }

        DeploymentFailureDismissal::updateOrCreate(
            ['cause_key' => $causeKey],
            ['last_seen_job_id' => $latestJob->id, 'dismissed_by' => $by->id, 'dismissed_at' => now()]
        );
    }
}
