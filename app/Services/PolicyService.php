<?php

namespace App\Services;

use App\Enums\DeploymentRing;
use App\Enums\JobAction;
use App\Enums\JobStatus;
use App\Enums\PolicyAction;
use App\Enums\PolicyVersionMode;
use App\Models\Computer;
use App\Models\DeploymentJob;
use App\Models\Package;
use App\Models\SoftwarePolicy;
use Illuminate\Support\Collection;

/**
 * Desired-state enforcement and compliance. Each policy is compared
 * against the fleet's reported software inventory; enforcement queues
 * only the remediation jobs needed to close the gap, and compliance
 * reports the same comparison without touching anything (Audit mode).
 */
class PolicyService
{
    public function __construct(
        private readonly DeploymentService $deployments,
        private readonly InstalledStateService $installedState,
    ) {
    }

    /** Failed/cancelled attempts re-queue at most once per this window. */
    private function failureBackoffHours(): int
    {
        return (int) app(SettingsService::class)->get('policies.failure_backoff_hours');
    }

    /* ─────────────────────────── Enforcement ─────────────────────────── */

    /**
     * Enforce one policy across its project. Returns jobs queued.
     * An operator's manual "Enforce now" passes $ignoreWindow — deliberate
     * clicks outrank the maintenance window, but never the ring rollout.
     */
    public function enforce(SoftwarePolicy $policy, bool $ignoreWindow = false): int
    {
        if (! $policy->isEnforceable()) {
            return 0;
        }

        if (! $ignoreWindow && ! $policy->isInWindow()) {
            return 0;
        }

        $excluded = $policy->excludedComputers()->pluck('computers.id')->all();

        $computers = $policy->targetComputers()->whereNotIn('id', $excluded)->get();

        // Three set-based queries answer what the per-computer path would ask
        // the database 15-20 times per machine — the difference between a
        // pass that scales with policies and one that scales with the fleet.
        $context = PolicyBatchContext::for($policy, $computers);

        $queued = 0;
        foreach ($computers as $computer) {
            if (! $this->ringEligible($policy, $computer)) {
                continue;
            }
            if ($this->enforceOn($policy, $computer, $context)) {
                $queued++;
            }
        }

        $policy->forceFill(['last_enforced_at' => now()])->saveQuietly();

        return $queued;
    }

    /**
     * Enforce every enforceable policy of the computer's project against
     * one machine — runs on every agent software report, so new machines
     * self-provision and drift heals automatically.
     */
    public function enforceForComputer(Computer $computer): int
    {
        $queued = 0;

        $policies = SoftwarePolicy::scopeQueryForComputer($computer)
            ->with('package')
            ->where('mode', \App\Enums\PolicyMode::Enforce)
            ->get();

        foreach ($policies as $policy) {
            if (! $policy->package->is_active) {
                continue;
            }
            // Automatic enforcement respects the schedule; the scheduled
            // policies:enforce run picks these up when the window opens.
            if (! $policy->isInWindow() || ! $this->ringEligible($policy, $computer)) {
                continue;
            }
            if ($policy->excludedComputers()->whereKey($computer->id)->exists()) {
                continue;
            }
            if ($this->enforceOn($policy, $computer)) {
                $queued++;
            }
        }

        return $queued;
    }

    /** Enforce every enforceable, in-window policy — the scheduler's entry point. */
    public function enforceAll(): int
    {
        $queued = 0;

        SoftwarePolicy::with(['package', 'project'])
            ->where('mode', \App\Enums\PolicyMode::Enforce)
            ->get()
            ->each(function (SoftwarePolicy $policy) use (&$queued) {
                $queued += $this->enforce($policy);
            });

        return $queued;
    }

    public function ringEligible(SoftwarePolicy $policy, Computer $computer): bool
    {
        if ($computer->ring === DeploymentRing::Emergency) {
            return true;
        }

        $eligibleAt = $policy->ringEligibleAt($computer->ring);

        return $eligibleAt === null || $eligibleAt->lte(now());
    }

    private function enforceOn(SoftwarePolicy $policy, Computer $computer, ?PolicyBatchContext $context = null): bool
    {
        // A package that cannot be queued at all must never reach queue()'s
        // DomainException from here: this call has no try/catch, so ONE
        // policy targeting an undeployable package would abort enforcement
        // for every other computer and policy in the same batch run. Real,
        // not hypothetical — the scheduled run currently holds a policy
        // enforcing Microsoft Edge, deactivated after it was found to be
        // permanently unable to install. Checked once here rather than in
        // both enforce() and enforceForComputer(), the two callers.
        if (! $policy->package->isDeployable()) {
            return false;
        }

        $remediation = $this->remediationFor($policy, $computer, $context);

        if ($remediation === null || $this->hasRelevantJob($policy, $computer, $context)) {
            return false;
        }

        $this->deployments->queue(
            computer: $computer,
            package: $policy->package,
            action: $remediation['action'],
            priority: $policy->priority,
            createdBy: $policy->created_by,
            targetVersion: $remediation['version'],
        );

        return true;
    }

    /* ─────────────────────────── Desired state ───────────────────────── */

    /**
     * The job (if any) that would bring this computer to the policy's
     * desired state — or null when already compliant / not applicable.
     *
     * @return array{action: JobAction, version: ?string}|null
     */
    public function remediationFor(SoftwarePolicy $policy, Computer $computer, ?PolicyBatchContext $context = null): ?array
    {
        $state = $context !== null
            ? $context->stateOf($computer)
            : $this->installedStateOn($policy->package, $computer);

        return match ($policy->action) {
            PolicyAction::Install => $state['present']
                ? $this->versionRemediation($policy, $state['version'])
                : [
                    'action'  => JobAction::Install,
                    'version' => $policy->version_mode === PolicyVersionMode::Exact ? $policy->desired_version : null,
                ],

            PolicyAction::Update => ! $state['present'] ? null : ($policy->version_mode === PolicyVersionMode::Latest
                ? ['action' => JobAction::Update, 'version' => null] // routine; cooldown sets the cadence
                : $this->versionRemediation($policy, $state['version'])),

            PolicyAction::ForceUpdate => $state['present'] && $policy->desired_version !== null
                ? $this->forceUpdateRemediation($policy)
                : null,

            PolicyAction::Uninstall, PolicyAction::Block => $state['present']
                ? ['action' => JobAction::Uninstall, 'version' => null]
                : null,
        };
    }

    /**
     * Force update on a package manager reinstalls the exact desired version
     * (a rollback job). Binary installers cannot fetch an arbitrary version,
     * so the honest action is to reinstall the package's current installer.
     *
     * @return array{action: JobAction, version: ?string}
     */
    private function forceUpdateRemediation(SoftwarePolicy $policy): array
    {
        return $policy->package->installer_type->supportsRollback()
            ? ['action' => JobAction::Rollback, 'version' => $policy->desired_version]
            : ['action' => JobAction::Install, 'version' => null];
    }

    /** @return array{action: JobAction, version: ?string}|null */
    private function versionRemediation(SoftwarePolicy $policy, ?string $installedVersion): ?array
    {
        if ($this->versionSatisfied($policy, $installedVersion)) {
            return null;
        }

        return match ($policy->version_mode) {
            // Exact and Freeze can mean a downgrade — the agent runs
            // rollback as `winget install --version X --force`. Only package
            // managers can do this; on binary installers a specific earlier
            // version is unreachable, so there is no remediation to queue.
            PolicyVersionMode::Exact,
            PolicyVersionMode::Maximum => $policy->package->installer_type->supportsRollback()
                ? ['action' => JobAction::Rollback, 'version' => $policy->desired_version]
                : null,
            PolicyVersionMode::Minimum => ['action' => JobAction::Update, 'version' => null],
            PolicyVersionMode::Latest => null,
        };
    }

    public function versionSatisfied(SoftwarePolicy $policy, ?string $installedVersion): bool
    {
        if ($policy->version_mode === PolicyVersionMode::Latest || $policy->desired_version === null) {
            return true;
        }

        // Version pinning only works where the inventory reports versions
        // we can trust (package managers); binary packages pass.
        if (! $policy->package->installer_type->requiresPackageManagerId()) {
            return true;
        }

        if ($installedVersion === null) {
            return false; // present but version unknown → cannot verify
        }

        return match ($policy->version_mode) {
            PolicyVersionMode::Exact => version_compare($installedVersion, $policy->desired_version, '=='),
            PolicyVersionMode::Minimum => version_compare($installedVersion, $policy->desired_version, '>='),
            PolicyVersionMode::Maximum => version_compare($installedVersion, $policy->desired_version, '<='),
            PolicyVersionMode::Latest => true,
        };
    }

    /**
     * Installed-state detection: package-manager packages match the
     * inventory exactly by id (and carry a version); binary packages fall
     * back to a successful-install job (version unknowable).
     *
     * @return array{present: bool, version: ?string}
     */
    public function installedStateOn(Package $package, Computer $computer): array
    {
        return $this->installedState->stateOf($package, $computer);
    }

    /* ─────────────────────────── Compliance ──────────────────────────── */

    /**
     * Per-computer compliance rows for a policy.
     *
     * @return Collection<int, array{computer: Computer, status: string, offline: bool, installed_version: ?string, reason: string, outdated: bool}>
     */
    public function complianceFor(SoftwarePolicy $policy): Collection
    {
        $excluded = $policy->excludedComputers()->pluck('computers.id')->flip();

        return $policy->targetComputers()->orderBy('hostname')->get()
            ->map(fn (Computer $computer) => $this->evaluate($policy, $computer, $excluded->has($computer->id)));
    }

    /**
     * Why each of the project's policies is — or is not — acting on this one
     * machine. The computer-centric inverse of complianceFor(): same
     * reasoning, asked from the other end, for "why is this not installed?".
     *
     * @return Collection<int, array{policy: SoftwarePolicy, status: string, reason: string, installed_version: ?string, outdated: bool}>
     */
    public function explainFor(Computer $computer): Collection
    {
        return SoftwarePolicy::scopeQueryForComputer($computer)
            ->with('package')
            ->get()
            ->map(function (SoftwarePolicy $policy) use ($computer) {
                // Two reasons nothing will ever happen, which the compliance
                // evaluation does not model because it never sees them.
                $excluded = $policy->excludedComputers()->whereKey($computer->id)->exists();

                if (! $policy->package->is_active) {
                    $row = $this->row($computer, 'disabled', null, 'Package is not active in the catalogue — no jobs will run');
                } elseif (! $policy->package->isDeployable()) {
                    // Checked ahead of the Audit branch too: auditRow() only
                    // ever reports drift, which would call an OS-managed or
                    // Store package "non-compliant" forever for a mismatch
                    // this platform was never able to act on either way.
                    $row = $this->row($computer, 'excluded', null, $policy->package->management_mode->clientExplanation());
                } elseif ($policy->mode === \App\Enums\PolicyMode::Disabled) {
                    $row = $this->row($computer, 'disabled', null, 'Policy is disabled');
                } elseif ($policy->mode === \App\Enums\PolicyMode::Audit && ! $excluded) {
                    // Audit reports drift and never acts, so the schedule is
                    // irrelevant to it. Saying "waiting for maintenance window"
                    // would promise something that is never coming.
                    $row = $this->auditRow($policy, $computer);
                } else {
                    $row = $this->evaluate($policy, $computer, $excluded);
                }

                return ['policy' => $policy] + $row;
            })
            ->sortBy(fn (array $row) => $row['policy']->package->name)
            ->values();
    }

    /**
     * An audit policy watches; it does not act. Its only two answers are
     * "matches desired state" and "does not" — the maintenance window, the
     * ring and the failure backoff all govern queueing, which will never
     * happen here, so quoting them would describe work that is not coming.
     *
     * @return array{computer: Computer, status: string, offline: bool, installed_version: ?string, reason: string, outdated: bool}
     */
    private function auditRow(SoftwarePolicy $policy, Computer $computer): array
    {
        $state = $this->installedStateOn($policy->package, $computer);

        if ($this->remediationFor($policy, $computer) === null) {
            return $this->row(
                $computer,
                'compliant',
                $state['version'],
                $this->compliantReason($policy, $state).$this->updateNote($policy, $computer),
                outdated: $this->isOutdated($policy, $computer),
            );
        }

        return $this->row(
            $computer,
            'non_compliant',
            $state['version'],
            $this->driftReason($policy, $state).' — audit only, so nothing will be queued'
        );
    }

    /**
     * One policy against one machine: its status and, in words, why.
     *
     * @return array{computer: Computer, status: string, offline: bool, installed_version: ?string, reason: string, outdated: bool}
     */
    private function evaluate(SoftwarePolicy $policy, Computer $computer, bool $excluded): array
    {
        $state = $this->installedStateOn($policy->package, $computer);

        if ($excluded) {
            return $this->row($computer, 'excluded', $state['version'], 'Excluded from this policy');
        }

        // enforce() already refuses to queue anything for a package this
        // platform cannot install (Store/OS-managed/Unsupported) -- but
        // nothing told the compliance report, so a policy that slipped onto
        // one of these showed a permanent, unfixable "Failed" or
        // "Non-compliant" that no retry or maintenance window would ever
        // clear. Same bucket manual exclusion already uses: correctly out
        // of the target denominator, since this platform was never going
        // to act on it either way.
        if (! $policy->package->isDeployable()) {
            return $this->row($computer, 'excluded', $state['version'], $policy->package->management_mode->clientExplanation());
        }

        $remediation = $this->remediationFor($policy, $computer);

        if ($remediation === null) {
            return $this->row(
                $computer,
                'compliant',
                $state['version'],
                $this->compliantReason($policy, $state).$this->updateNote($policy, $computer),
                outdated: $this->isOutdated($policy, $computer),
            );
        }

        // Routine latest-updates: a recent success means "current as of the
        // last run", not drift.
        if ($policy->action === PolicyAction::Update
            && $policy->version_mode === PolicyVersionMode::Latest
            && $this->hasRecentSuccess($policy, $computer, JobAction::Update)) {
            return $this->row($computer, 'compliant', $state['version'], 'Updated within the last ' . $policy->frequency->label() . ' run');
        }

        if ($this->hasJobInFlight($policy, $computer)) {
            return $this->row($computer, 'pending', $state['version'], 'Remediation job queued or running');
        }

        // Drift exists but the schedule holds it back.
        if (! $this->ringEligible($policy, $computer)) {
            $eligibleAt = $policy->ringEligibleAt($computer->ring);

            return $this->row($computer, 'scheduled', $state['version'],
                "{$computer->ring->label()} ring eligible {$eligibleAt->diffForHumans()}");
        }

        if (! $policy->isInWindow()) {
            return $this->row($computer, 'scheduled', $state['version'],
                "Waiting for maintenance window ({$policy->windowLabel()})");
        }

        if ($this->lastAttemptFailed($policy, $computer)) {
            return $this->row($computer, 'failed', $state['version'], 'Last attempt failed or was cancelled — backing off');
        }

        return $this->row($computer, 'non_compliant', $state['version'], $this->driftReason($policy, $state));
    }

    /**
     * @return array{target: int, compliant: int, compliant_outdated: int, pending: int, failed: int, non_compliant: int, excluded: int, offline: int, percent: ?float}
     */
    public function complianceSummary(SoftwarePolicy $policy): array
    {
        $rows = $this->complianceFor($policy);

        $counts = [
            'target'        => $rows->where('status', '!=', 'excluded')->count(),
            'compliant'     => $rows->where('status', 'compliant')->count(),
            // A subset of "compliant", never a different status — the policy
            // condition is genuinely met; this only says a newer version
            // exists too, which "Compliant" alone hides completely.
            'compliant_outdated' => $rows->where('status', 'compliant')->where('outdated', true)->count(),
            'pending'       => $rows->where('status', 'pending')->count(),
            'scheduled'     => $rows->where('status', 'scheduled')->count(),
            'failed'        => $rows->where('status', 'failed')->count(),
            'non_compliant' => $rows->where('status', 'non_compliant')->count(),
            'excluded'      => $rows->where('status', 'excluded')->count(),
            'offline'       => $rows->where('offline', true)->where('status', '!=', 'excluded')->count(),
        ];

        $counts['percent'] = $counts['target'] > 0
            ? round($counts['compliant'] / $counts['target'] * 100, 1)
            : null;

        return $counts;
    }

    /**
     * Fleet-wide widget: aggregate across every active, non-disabled
     * policy. Mirrors BrowserPolicyService::fleetSummary() — the same
     * "one number for the whole fleet" a hub tile or a dashboard card
     * needs, without hand-rolling a second summation of complianceSummary()
     * that could quietly drift from what the report itself shows.
     *
     * @return array{policies: int, target: int, compliant: int, compliant_outdated: int, percent: ?float}
     */
    public function fleetSummary(?int $tenantClientId = null): array
    {
        $policies = SoftwarePolicy::query()
            ->where('mode', '!=', \App\Enums\PolicyMode::Disabled)
            ->visibleTo($tenantClientId)
            ->get();

        $totals = ['policies' => $policies->count(), 'target' => 0, 'compliant' => 0, 'compliant_outdated' => 0];

        foreach ($policies as $policy) {
            $summary = $this->complianceSummary($policy);
            $totals['target'] += $summary['target'];
            $totals['compliant'] += $summary['compliant'];
            $totals['compliant_outdated'] += $summary['compliant_outdated'];
        }

        $totals['percent'] = $totals['target'] > 0
            ? round($totals['compliant'] / $totals['target'] * 100, 1)
            : null;

        return $totals;
    }

    /** @return array{computer: Computer, status: string, offline: bool, installed_version: ?string, reason: string, outdated: bool} */
    private function row(Computer $computer, string $status, ?string $version, string $reason, bool $outdated = false): array
    {
        return [
            'computer'          => $computer,
            'status'            => $status,
            'offline'           => ! $computer->isOnline(),
            'installed_version' => $version,
            'reason'            => $reason,
            // "Compliant" only ever means the policy's own condition is met
            // (see updateNote()'s docblock) — this is a second, independent
            // fact riding alongside it, never a reason to change the status.
            'outdated'          => $outdated,
        ];
    }

    /** @param array{present: bool, version: ?string} $state */
    private function compliantReason(SoftwarePolicy $policy, array $state): string
    {
        return match ($policy->action) {
            PolicyAction::Uninstall, PolicyAction::Block => 'Not installed',
            PolicyAction::Update, PolicyAction::ForceUpdate => $state['present'] ? 'Version satisfies the policy' : 'Not installed — not targeted',
            PolicyAction::Install => 'Installed' . ($state['version'] !== null ? " ({$state['version']})" : ''),
        };
    }

    /**
     * An Install policy is satisfied the moment the software is present, so a
     * machine two years out of date still reads "Compliant" — true, and
     * useless. The machine's package manager is the only thing that knows
     * something newer exists; where it says so, say so, without pretending the
     * policy is being violated. Choosing to update is the operator's call.
     */
    private function updateNote(SoftwarePolicy $policy, Computer $computer): string
    {
        $row = $this->reportedSoftware($policy, $computer);

        return $row !== null && $row->hasUpdate()
            ? " — {$row->available_version} available"
            : '';
    }

    /** The same fact updateNote() prints, as a plain boolean the fleet-wide compliance report can count. */
    private function isOutdated(SoftwarePolicy $policy, Computer $computer): bool
    {
        return $this->reportedSoftware($policy, $computer)?->hasUpdate() ?? false;
    }

    private function reportedSoftware(SoftwarePolicy $policy, Computer $computer): ?\App\Models\ComputerSoftware
    {
        if (! $policy->package->installer_type->requiresPackageManagerId()) {
            return null; // no package manager to ask
        }

        $id = $policy->package->installer_type === \App\Enums\InstallerType::Winget
            ? $policy->package->winget_id
            : $policy->package->choco_id;

        if ($id === null) {
            return null;
        }

        return $computer->software()
            ->where('source', $policy->package->installer_type->value)
            ->where('name', $id)
            ->first();
    }

    /** @param array{present: bool, version: ?string} $state */
    private function driftReason(SoftwarePolicy $policy, array $state): string
    {
        if (! $state['present']) {
            return 'Not installed';
        }

        if ($policy->version_mode->requiresVersion() && ! $this->versionSatisfied($policy, $state['version'])) {
            $installed = $state['version'] ?? 'unknown version';

            return "Installed {$installed}, policy wants {$policy->version_mode->label()} {$policy->desired_version}";
        }

        return match ($policy->action) {
            PolicyAction::Uninstall => 'Installed — should be removed',
            PolicyAction::Block => 'Blocked software detected',
            PolicyAction::Update => 'Update due',
            PolicyAction::ForceUpdate => 'Reinstall due',
            default => 'Out of desired state',
        };
    }

    /* ─────────────────────────── Job guards ──────────────────────────── */

    /** Anything that makes queueing another job pointless right now. */
    private function hasRelevantJob(SoftwarePolicy $policy, Computer $computer, ?PolicyBatchContext $context = null): bool
    {
        $inFlight = $context !== null
            ? $context->hasJobInFlight($computer)
            : $this->hasJobInFlight($policy, $computer);

        if ($inFlight) {
            return true;
        }

        $recentSuccess = fn (JobAction $action): bool => $context !== null
            ? $context->hasRecentSuccess($computer, $action, $policy->frequency->cooldownHours())
            : $this->hasRecentSuccess($policy, $computer, $action);

        // Routine latest-updates: at most one per frequency window.
        if ($policy->action === PolicyAction::Update
            && $policy->version_mode === PolicyVersionMode::Latest
            && $recentSuccess(JobAction::Update)) {
            return true;
        }

        // Installs are paced too: if we successfully installed this package
        // here within the window and the policy still reads it as missing,
        // reality won't change by installing again — the inventory is what
        // needs to catch up. One attempt per window, not seventeen.
        if ($recentSuccess(JobAction::Install)) {
            return true;
        }

        // Force update: at most one reinstall per frequency window.
        if ($policy->action === PolicyAction::ForceUpdate && $recentSuccess(JobAction::Rollback)) {
            return true;
        }

        // Failed attempts are not hammered, and an operator's cancel is
        // respected for the same window — desired state re-asserts after
        // the backoff (exclude the machine to opt out permanently).
        if ($context !== null) {
            return $context->failedRecently($computer, $this->failureBackoffHours());
        }

        return DeploymentJob::where('computer_id', $computer->id)
            ->where('package_id', $policy->package_id)
            ->whereIn('status', [JobStatus::Failed, JobStatus::Cancelled])
            // A permanent failure never re-queues — only an operator's manual
            // retry (which resets the job) can restart enforcement here.
            ->where(fn ($q) => $q
                ->where('finished_at', '>=', now()->subHours($this->failureBackoffHours()))
                ->orWhereIn('exit_code', DeploymentJob::PERMANENT_EXIT_CODES))
            ->exists();
    }

    private function hasJobInFlight(SoftwarePolicy $policy, Computer $computer): bool
    {
        return DeploymentJob::where('computer_id', $computer->id)
            ->where('package_id', $policy->package_id)
            ->whereIn('status', [JobStatus::Pending, JobStatus::Blocked, JobStatus::Running])
            ->exists();
    }

    private function hasRecentSuccess(SoftwarePolicy $policy, Computer $computer, JobAction $action): bool
    {
        return DeploymentJob::where('computer_id', $computer->id)
            ->where('package_id', $policy->package_id)
            ->where('action', $action)
            ->where('status', JobStatus::Succeeded)
            ->where('finished_at', '>=', now()->subHours($policy->frequency->cooldownHours()))
            ->exists();
    }

    private function lastAttemptFailed(SoftwarePolicy $policy, Computer $computer): bool
    {
        return DeploymentJob::where('computer_id', $computer->id)
            ->where('package_id', $policy->package_id)
            ->whereIn('status', [JobStatus::Failed, JobStatus::Cancelled])
            ->where(fn ($q) => $q
                ->where('finished_at', '>=', now()->subHours($this->failureBackoffHours()))
                ->orWhereIn('exit_code', DeploymentJob::PERMANENT_EXIT_CODES))
            ->exists();
    }
}
