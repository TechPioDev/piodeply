<?php

namespace App\Console\Commands;

use App\Enums\PolicyMode;
use App\Models\SoftwarePolicy;
use App\Services\NotificationService;
use App\Services\PolicyService;
use Illuminate\Console\Command;

class SendDriftDigest extends Command
{
    protected $signature = 'policies:drift-digest';

    protected $description = 'Send a digest of policies with failed or drifted machines';

    public function handle(PolicyService $policies, NotificationService $notifications): int
    {
        $drifted = SoftwarePolicy::with(['project.client', 'package'])
            ->where('mode', '!=', PolicyMode::Disabled)
            ->get()
            ->map(fn (SoftwarePolicy $policy) => [
                'policy'  => $policy,
                'summary' => $policies->complianceSummary($policy),
            ])
            ->filter(fn (array $row) => $row['summary']['failed'] > 0 || $row['summary']['non_compliant'] > 0);

        if ($drifted->isEmpty()) {
            $this->info('No drift — nothing to send.');

            return self::SUCCESS;
        }

        $data = $drifted->mapWithKeys(function (array $row) {
            $summary = $row['summary'];
            $parts = array_filter([
                $summary['failed'] > 0 ? "{$summary['failed']} failed" : null,
                $summary['non_compliant'] > 0 ? "{$summary['non_compliant']} drifted" : null,
            ]);

            return [$row['policy']->label() . ' (' . $row['policy']->scopeName() . ')' => implode(', ', $parts) . " — {$summary['percent']}% compliant"];
        })->all();

        $notifications->notify(
            'policy.drift',
            'Compliance drift digest: ' . $drifted->count() . ' policy(ies) need attention',
            $data
        );

        $this->info("Drift digest sent — {$drifted->count()} policy(ies) reported.");

        return self::SUCCESS;
    }
}
