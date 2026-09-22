<?php

namespace App\Services;

use App\Models\Project;

/**
 * Renders ready-to-run enrollment scripts for a project.
 *
 * The project's API key is never stored in a form we can read back (it is
 * hashed), so the operator supplies it and we render around it. Nothing here
 * persists the key.
 */
class EnrollmentScriptService
{
    /**
     * The agent version a machine must be at or above. Bump alongside
     * agent/src/PioDeploy.Agent/PioDeploy.Agent.csproj — it is what makes
     * the GPO script upgrade an already-enrolled fleet rather than skip it.
     */
    public const CURRENT_AGENT_VERSION = '1.4.25';

    /** Shown when the operator has not pasted their key yet. */
    public const KEY_PLACEHOLDER = 'pio_PASTE_YOUR_PROJECT_API_KEY_HERE';

    /**
     * What a project key may contain. Shared with the browser, which does the
     * substitution locally so the key never reaches the server — both ends
     * must agree on what they will accept.
     */
    public const KEY_PATTERN = '^[A-Za-z0-9_\\-]{8,128}$';

    /** @return array<string, array{label: string, filename: string, language: string, body: string}> */
    public function all(Project $project, ?string $apiKey): array
    {
        return [
            'gpo' => [
                'label'    => 'Group Policy (Active Directory)',
                'filename' => 'Install-PioDeployAgent.ps1',
                'language' => 'powershell',
                'body'     => $this->render('gpo', $project, $apiKey),
            ],
            'intune' => [
                'label'    => 'Intune / Entra',
                'filename' => 'Install-PioDeployAgent-Intune.ps1',
                'language' => 'powershell',
                'body'     => $this->render('intune', $project, $apiKey),
            ],
            'rmm' => [
                'label'    => 'RMM / one-liner',
                'filename' => 'install-piodeploy-agent.ps1',
                'language' => 'powershell',
                'body'     => $this->render('rmm', $project, $apiKey),
            ],
            'single' => [
                'label'    => 'Single machine',
                'filename' => 'install-piodeploy-agent.ps1',
                'language' => 'powershell',
                'body'     => $this->render('single', $project, $apiKey),
            ],
            'uninstall' => [
                'label'    => 'Uninstall / remove agent',
                'filename' => 'uninstall-piodeploy-agent.ps1',
                'language' => 'powershell',
                'body'     => $this->render('uninstall', $project, $apiKey),
            ],
        ];
    }

    /**
     * These templates are PowerShell, not HTML, so they interpolate unescaped
     * — Blade's {{ }} would turn an apostrophe into &#039; and corrupt the
     * script. Everything variable is therefore made safe here instead, for
     * the syntax it actually lands in.
     */
    private function render(string $method, Project $project, ?string $apiKey): string
    {
        $rendered = view("agent.enrollment.{$method}", [
            'project'    => $project,
            'name'       => $this->comment($project->name),
            'company'    => $this->comment($project->client->company_name),
            'apiKey'     => $this->key($apiKey),
            'scriptUrl'  => route('agent.download', $project->download_token),
            'minVersion' => self::CURRENT_AGENT_VERSION,
        ])->render();

        return trim(self::stripBom($rendered));
    }

    /**
     * A UTF-8 BOM (an editor artefact, not something anyone writes on
     * purpose) silently breaks every script this service renders: fed into
     * [scriptblock]::Create() -- how every enrollment path (GPO, Intune,
     * RMM, single-machine) runs the downloaded installer -- it stops
     * #Requires and param() from being recognised at all. PowerShell then
     * runs both as bare commands, logs two harmless-looking errors, and
     * carries on with $ApiKey left empty for the rest of the script: the
     * agent installs, the service starts, and it can never authenticate --
     * with nothing in the transcript loud enough to explain why. trim()
     * does not touch this; it is three raw bytes, not whitespace.
     */
    public static function stripBom(string $text): string
    {
        return str_starts_with($text, "\xEF\xBB\xBF") ? substr($text, 3) : $text;
    }

    /**
     * A project key is an opaque token, so this whitelists rather than
     * escapes. Escaping was the wrong tool: PowerShell ends a single-quoted
     * string on four Unicode quotes as well as the ASCII one (U+2018, U+2019,
     * U+201A, U+201B), so doubling ' let a crafted key close the literal and
     * run as SYSTEM on every machine the script reached. Enumerating the
     * characters that can hurt is a losing game; accepting only the ones a
     * real key contains is not.
     */
    public static function looksLikeAKey(string $candidate): bool
    {
        return preg_match('/'.self::KEY_PATTERN.'/', $candidate) === 1;
    }

    /** Substituted into a single-quoted PowerShell literal by the templates. */
    private function key(?string $apiKey): string
    {
        $key = trim((string) $apiKey);

        // Anything unrecognisable becomes the placeholder: a script that
        // visibly will not run beats one that runs something unintended.
        return $key !== '' && self::looksLikeAKey($key) ? $key : self::KEY_PLACEHOLDER;
    }

    /**
     * Text bound for the <# … #> banner. A project named "#>" would close
     * the comment and turn the rest of the banner into code.
     */
    private function comment(string $text): string
    {
        return trim(str_replace(['#>', "\r", "\n"], ['# >', ' ', ' '], $text));
    }
}
