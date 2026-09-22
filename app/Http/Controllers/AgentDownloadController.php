<?php

namespace App\Http\Controllers;

use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Services\EnrollmentScriptService;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public agent-download endpoints, keyed by the project's download token
 * (the token is the secret; the API key is never embedded — it is passed
 * by the operator when running the script).
 */
class AgentDownloadController extends Controller
{
    /** Where an admin drops the published agent bundle. */
    public const BUNDLE_PATH = 'agent/PioDeployAgent.zip';

    public function script(string $token): Response
    {
        $project = $this->resolveProject($token);

        $serverUrl = rtrim(config('app.url'), '/');
        $binaryUrl = route('agent.download.binary', $project->download_token);
        $hasBundle = Storage::disk('local')->exists(self::BUNDLE_PATH);

        $script = EnrollmentScriptService::stripBom(view('agent.install-script', [
            'project'   => $project,
            'serverUrl' => $serverUrl,
            'binaryUrl' => $binaryUrl,
            'hasBundle' => $hasBundle,
        ])->render());

        return response($script, 200, [
            'Content-Type'        => 'text/plain; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="install-piodeploy-agent.ps1"',
        ]);
    }

    public function binary(string $token): Response
    {
        $this->resolveProject($token);

        abort_unless(
            Storage::disk('local')->exists(self::BUNDLE_PATH),
            404,
            'No agent bundle has been published yet. Publish the agent (dotnet publish) and place the zip at storage/app/private/' . self::BUNDLE_PATH . '.'
        );

        return Storage::disk('local')->download(self::BUNDLE_PATH, 'PioDeployAgent.zip');
    }

    private function resolveProject(string $token): Project
    {
        $project = Project::where('download_token', $token)->first();

        abort_if($project === null, 404);
        abort_if($project->status !== ProjectStatus::Active, 404);

        return $project;
    }
}
