<?php

namespace App\Services;

use App\Enums\JobStatus;
use App\Models\Client;
use App\Models\Computer;
use App\Models\DeploymentJob;
use App\Models\SoftwarePolicy;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * The client-facing compliance report: one branded PDF summarising a single
 * client's fleet, policy compliance and recent deployment activity. Every
 * query is scoped through the client's projects, so nothing from another
 * tenant can appear no matter who requests it.
 */
class ClientComplianceReportService
{
    public function __construct(
        private readonly PolicyService $policies,
        private readonly BrowserPolicyService $browserPolicies,
    ) {
    }

    /** All data the PDF view needs. */
    public function dataFor(Client $client): array
    {
        $computers = Computer::whereHas('project', fn ($q) => $q->withTrashed()->where('client_id', $client->id))
            ->with('project')
            ->orderBy('hostname')
            ->get();

        $softwarePolicies = SoftwarePolicy::with('package', 'project')
            ->visibleTo($client->id)
            // Same filter as the compliance report page: Enforce and Audit
            // policies both report; only Disabled ones are inert. (There is
            // no "status" column here — that's BrowserPolicy's vocabulary.)
            ->where('mode', '!=', \App\Enums\PolicyMode::Disabled)
            ->orderBy('id')
            ->get()
            ->map(fn (SoftwarePolicy $policy) => [
                'policy'  => $policy,
                'summary' => $this->policies->complianceSummary($policy),
            ]);

        $jobs = DeploymentJob::whereIn('computer_id', $computers->modelKeys())
            ->where('created_at', '>=', now()->subDays(30))
            ->get();

        return [
            'client'    => $client,
            'generated' => now(),
            'period'    => now()->subDays(30)->toDateString().' — '.now()->toDateString(),
            'company'   => app(SettingsService::class)->get('branding.company_name'),

            'fleet' => [
                'total'           => $computers->count(),
                'online'          => $computers->filter->isOnline()->count(),
                'offline'         => $computers->reject->isOnline()->count(),
                'agents_outdated' => $computers->filter->isAgentOutdated()->count(),
            ],
            'computers' => $computers,

            'software' => $softwarePolicies,
            // The browser section below already leads with a fleet-wide
            // tile row; software compliance had no equivalent at all, only
            // the per-policy table, so a reader had no single "are we
            // protected" figure for software the way they do for browsers.
            'softwareFleet' => $this->policies->fleetSummary($client->id),
            'browser'  => $this->browserPolicies->fleetSummary($client->id),

            'deployments' => [
                'total'     => $jobs->count(),
                'succeeded' => $jobs->where('status', JobStatus::Succeeded)->count(),
                'failed'    => $jobs->where('status', JobStatus::Failed)->count(),
            ],
        ];
    }

    /** The rendered PDF, ready to download or attach. */
    public function pdfFor(Client $client): \Barryvdh\DomPDF\PDF
    {
        return Pdf::loadView('reports.client-compliance-pdf', $this->dataFor($client))
            ->setPaper('a4');
    }

    public function filenameFor(Client $client): string
    {
        return str($client->company_name)->slug().'-compliance-'.now()->format('Y-m').'.pdf';
    }
}
