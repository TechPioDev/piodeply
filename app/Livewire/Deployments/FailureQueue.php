<?php

namespace App\Livewire\Deployments;

use App\Models\DeploymentJob;
use App\Services\FailureQueueService;
use Livewire\Component;

/**
 * "What is currently broken, and whose job is it to fix?" — the answer the
 * escalation emails give once and then lose. This is the standing list: it
 * shrinks on its own when a fix actually works, and an operator can clear
 * anything they've dealt with by hand.
 */
class FailureQueue extends Component
{
    public function mount(): void
    {
        $this->authorize('viewAny', DeploymentJob::class);
    }

    public function dismiss(string $causeKey, int $latestJobId, FailureQueueService $queue): void
    {
        $job = DeploymentJob::findOrFail($latestJobId);

        // Coarse gate: can this user manage deployments at all. The finer
        // rule — staff-only for a package cause, tenant-matched for a
        // machine one — lives in the service, since dismissal is shared
        // state and the wrong person clearing it hides it from everyone.
        $this->authorize('manage', $job);

        $queue->markHandled($causeKey, $job, auth()->user());

        session()->flash('status', 'Marked handled.');
    }

    public function render(FailureQueueService $queue)
    {
        return view('livewire.deployments.failure-queue', [
            'items' => $queue->attentionItems(auth()->user()),
        ])->layout('layouts.app');
    }
}
