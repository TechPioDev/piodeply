<?php

namespace App\Livewire\Reports;

use App\Enums\Permission;
use App\Models\SoftwarePolicy;
use App\Services\PolicyService;
use Livewire\Component;

/**
 * Fleet-wide policy compliance: one row per active policy with its
 * compliance buckets, plus an overall roll-up.
 */
class ComplianceReport extends Component
{
    public string $projectFilter = '';

    private function policies()
    {
        $tenantId = auth()->user()->tenantClientId();

        return SoftwarePolicy::query()
            ->with(['project.client', 'package'])
            ->where('mode', '!=', \App\Enums\PolicyMode::Disabled)
            ->visibleTo($tenantId)
            ->when($this->projectFilter !== '', fn ($q) => $q->where('project_id', $this->projectFilter))
            ->orderBy('project_id')->orderBy('priority')
            ->get();
    }

    /** @return array{rows: \Illuminate\Support\Collection, overall: array} */
    private function build(PolicyService $service): array
    {
        $rows = $this->policies()->map(fn (SoftwarePolicy $policy) => [
            'policy'  => $policy,
            'summary' => $service->complianceSummary($policy),
        ]);

        $overall = [
            'policies'      => $rows->count(),
            'target'        => $rows->sum(fn ($row) => $row['summary']['target']),
            'compliant'     => $rows->sum(fn ($row) => $row['summary']['compliant']),
            'compliant_outdated' => $rows->sum(fn ($row) => $row['summary']['compliant_outdated']),
            'failed'        => $rows->sum(fn ($row) => $row['summary']['failed']),
            'non_compliant' => $rows->sum(fn ($row) => $row['summary']['non_compliant']),
        ];
        $overall['percent'] = $overall['target'] > 0
            ? round($overall['compliant'] / $overall['target'] * 100, 1)
            : null;

        return ['rows' => $rows, 'overall' => $overall];
    }

    public function export(PolicyService $service)
    {
        abort_unless(auth()->user()->can(Permission::ReportsExport->value), 403);

        $rows = $this->build($service)['rows'];

        $csv = implode(",", ['Policy', 'Client', project_term(), 'Action', 'Mode', 'Target', 'Compliant',
            'Compliant but outdated', 'Pending', 'Scheduled', 'Failed', 'Non-compliant', 'Excluded', 'Offline', 'Compliance %']) . "\n";

        foreach ($rows as $row) {
            $summary = $row['summary'];
            $csv .= implode(',', array_map(
                fn ($value) => '"' . str_replace('"', '""', (string) $value) . '"',
                [
                    $row['policy']->label(),
                    $row['policy']->project?->client?->company_name ?? '',
                    $row['policy']->scopeName(),
                    $row['policy']->action->label(),
                    $row['policy']->mode->label(),
                    $summary['target'], $summary['compliant'], $summary['compliant_outdated'], $summary['pending'], $summary['scheduled'],
                    $summary['failed'], $summary['non_compliant'], $summary['excluded'], $summary['offline'],
                    $summary['percent'] ?? '',
                ]
            )) . "\n";
        }

        return response()->streamDownload(
            fn () => print($csv),
            'piodeploy-compliance-' . now()->format('Ymd-His') . '.csv',
            ['Content-Type' => 'text/csv']
        );
    }

    public function render(PolicyService $service)
    {
        abort_unless(auth()->user()->can(Permission::ReportsView->value), 403);

        $tenantId = auth()->user()->tenantClientId();

        return view('livewire.reports.compliance-report', $this->build($service) + [
            'projects' => \App\Models\Project::orderBy('name')
                ->when($tenantId !== null, fn ($q) => $q->where('client_id', $tenantId))
                ->get(['id', 'name']),
        ])->layout('layouts.app');
    }
}
