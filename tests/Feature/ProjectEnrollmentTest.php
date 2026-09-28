<?php

namespace Tests\Feature;

use App\DTOs\ProjectData;
use App\Enums\Role as RoleEnum;
use App\Livewire\Projects\ProjectEnrollment;
use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use App\Services\EnrollmentScriptService;
use App\Services\ProjectService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class ProjectEnrollmentTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->project = app(ProjectService::class)->create(new ProjectData(
            clientId: Client::factory()->create(['company_name' => 'Acme Ltd'])->id,
            name: 'Acme Fleet',
        ))['project'];
    }

    private function admin(): User
    {
        return tap(User::factory()->create(), fn (User $u) => $u->assignRole(RoleEnum::Admin->value));
    }

    private function page()
    {
        return Livewire::actingAs($this->admin())
            ->test(ProjectEnrollment::class, ['project' => $this->project]);
    }

    public function test_the_page_offers_every_rollout_method(): void
    {
        $this->page()
            ->assertOk()
            ->assertSee('Group Policy (Active Directory)')
            ->assertSee('Intune / Entra')
            ->assertSee('RMM / one-liner')
            ->assertSee('Single machine')
            ->assertSee('Uninstall / remove agent');
    }

    public function test_keys_can_be_created_and_revoked_from_the_page(): void
    {
        $page = $this->page()
            ->set('newKeyLabel', 'London office')
            ->call('createKey');

        // Shown once, valid shape, and stored only as a hash.
        $revealed = $page->get('revealedKey');
        $this->assertTrue(EnrollmentScriptService::looksLikeAKey($revealed));
        $this->assertTrue(Project::findByApiKey($revealed)->is($this->project));

        $key = $this->project->apiKeys()->where('label', 'London office')->first();
        $this->assertNotNull($key);

        $this->page()->call('revokeKey', $key->id);
        $this->assertNull(Project::findByApiKey($revealed), 'revoked key must stop authenticating');
    }

    public function test_the_revoked_count_only_shows_once_theres_something_to_report(): void
    {
        $this->page()->assertDontSee('revoked');

        $page = $this->page()->set('newKeyLabel', 'Temp key')->call('createKey');
        $key = $this->project->apiKeys()->where('label', 'Temp key')->first();
        $this->page()->call('revokeKey', $key->id);

        $this->page()->assertSee('1 revoked');
    }

    /**
     * created_by is stamped on every key and already has its own creator()
     * relation -- but the table never showed it, so a live fleet credential
     * carried no record of who actually issued it.
     */
    public function test_the_table_shows_who_created_each_key(): void
    {
        $admin = $this->admin();
        Livewire::actingAs($admin)
            ->test(ProjectEnrollment::class, ['project' => $this->project])
            ->set('newKeyLabel', 'London office')
            ->call('createKey');

        $this->page()->assertSee($admin->name);
    }

    public function test_key_management_requires_the_rotate_permission(): void
    {
        $viewer = tap(User::factory()->create(), fn (User $u) => $u->assignRole(RoleEnum::Viewer->value));

        Livewire::actingAs($viewer)
            ->test(ProjectEnrollment::class, ['project' => $this->project])
            ->call('createKey')
            ->assertForbidden();
    }

    public function test_the_page_explains_fresh_vms_are_auto_prepared(): void
    {
        $this->page()
            ->assertSee('Fresh machines & VMs are prepared automatically')
            ->assertSee('-1073741515');
    }

    public function test_the_gpo_script_carries_the_projects_download_url(): void
    {
        $this->page()->assertSee(route('agent.download', $this->project->download_token));
    }

    /**
     * Livewire serialises public properties into the page and posts them on
     * every update, so the key is not one. The server renders a placeholder
     * and the browser substitutes locally.
     */
    public function test_the_api_key_is_never_a_component_property(): void
    {
        $this->assertFalse(
            property_exists(ProjectEnrollment::class, 'apiKey'),
            'binding the key to Livewire would put a live fleet credential in the DOM'
        );

        $this->page()
            ->assertSee(EnrollmentScriptService::KEY_PLACEHOLDER)
            ->assertSee('never sent to the server');
    }

    public function test_the_rendered_script_always_carries_the_placeholder(): void
    {
        $this->page()
            ->assertSee(EnrollmentScriptService::KEY_PLACEHOLDER)
            ->assertSee('No key entered');
    }

    public function test_a_key_cannot_break_out_of_the_powershell_literal(): void
    {
        // Anything that is not key-shaped never reaches the script at all.
        $attacks = [
            "pio_x'; Remove-Item C:\\ -Recurse; '",   // ASCII quote
            "pio_a\nRemove-Item C:\\",                 // newline ends a statement
            "pio_x\u{2019}; Write-Output PWNED; \u{2018}x", // U+2019/U+2018 close a PS literal
            "pio_x\u{201A}; Write-Output PWNED; \u{201B}x", // U+201A/U+201B likewise
            "pio_x`; Write-Output PWNED",              // backtick
            "pio_x\x00; Write-Output PWNED",           // null byte
        ];

        foreach ($attacks as $attack) {
            $body = app(EnrollmentScriptService::class)->all($this->project, $attack)['gpo']['body'];

            $this->assertStringContainsString(EnrollmentScriptService::KEY_PLACEHOLDER, $body, 'rejected key should fall back to the placeholder');
            $this->assertStringNotContainsString('PWNED', $body);
            $this->assertStringNotContainsString('Remove-Item C:\\', $body);
        }
    }

    /**
     * PowerShell ends a single-quoted string on four Unicode quotes as well as
     * the ASCII one, so escaping by doubling ' was not enough.
     */
    public function test_a_unicode_quote_cannot_close_the_powershell_literal(): void
    {
        foreach (["\u{2018}", "\u{2019}", "\u{201A}", "\u{201B}", "'"] as $quote) {
            $this->assertFalse(
                EnrollmentScriptService::looksLikeAKey("pio_abc{$quote}rest"),
                "a key containing {$quote} must be rejected"
            );
        }
    }

    public function test_a_real_key_is_accepted(): void
    {
        $this->assertTrue(EnrollmentScriptService::looksLikeAKey('pio_gTwGgtN0ZqZjS2abcdef1234567890'));
        $this->assertTrue(EnrollmentScriptService::looksLikeAKey('pio_with-dashes_and_underscores'));

        $this->assertFalse(EnrollmentScriptService::looksLikeAKey('short'));
        $this->assertFalse(EnrollmentScriptService::looksLikeAKey(str_repeat('a', 129)));
        $this->assertFalse(EnrollmentScriptService::looksLikeAKey('has spaces in it'));
    }

    public function test_the_browser_is_given_the_same_rule_the_server_enforces(): void
    {
        // The substitution happens client-side, so the two must agree on what
        // a key is — a looser rule in the browser would reopen the injection.
        $this->page()
            ->assertViewHas('keyPattern', EnrollmentScriptService::KEY_PATTERN)
            ->assertSee('does not look like a '.project_term_lower().' key');

        $this->assertTrue(EnrollmentScriptService::looksLikeAKey('pio_abcdefgh12345678'));
        $this->assertFalse(EnrollmentScriptService::looksLikeAKey("pio_abcdefgh\u{2019}; calc"));
    }

    public function test_a_project_name_cannot_close_the_comment_banner(): void
    {
        $this->project->update(['name' => 'Acme #> ; Remove-Item C:\ -Recurse ; <#']);

        $body = app(EnrollmentScriptService::class)->all($this->project->fresh(), 'pio_k')['gpo']['body'];

        // The banner stays a banner.
        $this->assertStringNotContainsString('#> ; Remove-Item', $body);
        $this->assertStringContainsString('# > ; Remove-Item', $body);
    }

    public function test_an_apostrophe_in_a_client_name_does_not_corrupt_the_script(): void
    {
        // Blade's {{ }} would render this as &#039; — plain-text scripts must
        // not be HTML-escaped.
        $this->project->client->update(['company_name' => "O'Brien & Sons"]);

        $body = app(EnrollmentScriptService::class)->all($this->project->fresh(), 'pio_k')['gpo']['body'];

        $this->assertStringContainsString("O'Brien & Sons", $body);
        $this->assertStringNotContainsString('&#039;', $body);
        $this->assertStringNotContainsString('&amp;', $body);
    }

    public function test_the_gpo_script_is_idempotent_and_version_aware(): void
    {
        $body = app(EnrollmentScriptService::class)->all($this->project, 'pio_k')['gpo']['body'];

        // Exits when already current, rather than reinstalling every boot.
        $this->assertStringContainsString('Get-Service -Name $serviceName', $body);
        $this->assertStringContainsString('-ge [version]$minVersion', $body);
        // and upgrades a fleet left on an older build.
        $this->assertStringContainsString(EnrollmentScriptService::CURRENT_AGENT_VERSION, $body);
    }

    public function test_every_method_runs_the_installer_in_memory_not_from_a_saved_file(): void
    {
        // Execution policy only blocks running a .ps1 *file*. Building the
        // installer as an in-memory scriptblock sidesteps a Restricted policy
        // entirely — the failure a fresh machine hit. Guard against a regression
        // to the save-to-temp-then-run pattern on any method.
        $all = app(EnrollmentScriptService::class)->all($this->project, 'pio_k');

        foreach (['single', 'rmm', 'gpo', 'intune'] as $method) {
            $body = $all[$method]['body'];
            $this->assertStringContainsString('[scriptblock]::Create(', $body, "$method should run the installer in memory");
            $this->assertStringNotContainsString('OutFile $installer', $body, "$method must not save the installer to a file");
            $this->assertStringNotContainsString("& \$installer -ApiKey", $body, "$method must not run the installer from a file");
        }
    }

    public function test_the_single_machine_command_is_one_self_contained_line(): void
    {
        // A real-length key (KEY_PATTERN needs >= 8 chars) so we can assert it
        // is carried inline rather than falling back to the placeholder.
        $body = app(EnrollmentScriptService::class)->all($this->project, 'pio_realkey1')['single']['body'];

        // Exactly one executable (non-comment, non-blank) line: nothing can be
        // pasted out of order, and it carries the key inline.
        $exec = collect(preg_split('/\R/', $body))
            ->map(fn ($l) => trim($l))
            ->filter(fn ($l) => $l !== '' && ! str_starts_with($l, '#'))
            ->values();

        $this->assertCount(1, $exec, 'the single-machine command must be one line');
        $this->assertStringContainsString('[scriptblock]::Create(', $exec[0]);
        $this->assertStringContainsString("-ApiKey 'pio_realkey1'", $exec[0]);
    }

    public function test_the_uninstall_script_removes_the_agent_and_never_needs_a_key(): void
    {
        // The escape hatch for a machine whose agent is broken or offline —
        // the portal button can't reach those. Local-only, so no API key may
        // appear in it: it gets pasted into tickets and left on desktops.
        $body = app(EnrollmentScriptService::class)->all($this->project, 'pio_realkey1')['uninstall']['body'];

        $this->assertStringContainsString('sc.exe delete PioDeployAgent', $body);
        $this->assertStringContainsString("Remove-Item 'C:\Program Files\PioDeploy'", $body);
        $this->assertStringContainsString("Remove-Item 'C:\ProgramData\PioDeploy'", $body);
        $this->assertStringNotContainsString('pio_realkey1', $body, 'the uninstall script must not carry the key');
    }

    public function test_the_installer_sets_up_a_self_healing_watchdog(): void
    {
        // The reliability guarantee: a scheduled task restarts the service if
        // anything leaves it stopped, and Windows recovery restarts it on a
        // crash. Both must be in the script every enrolment downloads.
        $body = \App\Models\AgentDownloadController::class; // touch autoload
        $script = view('agent.install-script', [
            'project'   => $this->project,
            'serverUrl' => 'https://piodeploy.com',
            'binaryUrl' => 'https://piodeploy.com/download/agent/x/binary',
            'hasBundle' => true,
        ])->render();

        $this->assertStringContainsString('PioDeployAgentWatchdog', $script);
        $this->assertStringContainsString('/SC MINUTE', $script);
        $this->assertStringContainsString('sc.exe failure', $script);
    }

    public function test_the_installer_sets_up_the_status_tray_helper(): void
    {
        $script = view('agent.install-script', [
            'project'   => $this->project,
            'serverUrl' => 'https://piodeploy.com',
            'binaryUrl' => 'https://piodeploy.com/download/agent/x/binary',
            'hasBundle' => true,
        ])->render();

        // The per-user tray, launched at logon, reading the service's status.
        $this->assertStringContainsString('PioDeployAgentTray', $script);
        $this->assertStringContainsString('/SC ONLOGON', $script);
        $this->assertStringContainsString('pio-tray.ps1', $script);
        $this->assertStringContainsString('NotifyIcon', $script);
    }

    /**
     * The Visual C++ redistributable download+install used to run on every
     * single enrollment, unconditionally -- a ~15-25MB fetch and a minute or
     * more of installer overhead, even on the (typical) machine where winget
     * already works and nothing needed repairing at all. It is only there to
     * fix winget when winget is actually broken, so it must appear only
     * inside that repair branch, checked after Test-WingetWorks -- never
     * ahead of it.
     */
    public function test_the_vc_runtime_download_only_runs_when_winget_is_actually_broken(): void
    {
        $script = view('agent.install-script', [
            'project'   => $this->project,
            'serverUrl' => 'https://piodeploy.com',
            'binaryUrl' => 'https://piodeploy.com/download/agent/x/binary',
            'hasBundle' => true,
        ])->render();

        $wingetCheckPos = strpos($script, 'if (Test-WingetWorks)');
        $vcDownloadPos = strpos($script, "Invoke-WebRequest -Uri 'https://aka.ms/vs/17/release/vc_redist.x64.exe'");

        $this->assertNotFalse($wingetCheckPos);
        $this->assertNotFalse($vcDownloadPos);
        $this->assertGreaterThan($wingetCheckPos, $vcDownloadPos, 'the VC++ runtime must only be fetched after winget has already proven broken, not unconditionally');
    }

    /**
     * A "2.5 hour install" turned out to be this exact path: a genuinely
     * fresh physical machine (unlike the already-provisioned test machine
     * the previous network fix was timed on) hits the winget-repair branch,
     * and Repair-WinGetPackageManager has no timeout of its own — it has
     * been seen, in the field, to hang for a very long time waiting on
     * Microsoft Store services. Every network call in the installer must
     * either carry an explicit -TimeoutSec or run under Invoke-WithTimeout,
     * so the worst case is bounded.
     */
    public function test_every_network_call_in_the_installer_is_time_bounded(): void
    {
        $script = view('agent.install-script', [
            'project'   => $this->project,
            'serverUrl' => 'https://piodeploy.com',
            'binaryUrl' => 'https://piodeploy.com/download/agent/x/binary',
            'hasBundle' => true,
        ])->render();

        $this->assertStringContainsString('function Invoke-WithTimeout', $script);

        // Every actual Invoke-WebRequest call (not a comment mentioning the
        // cmdlet by name) must carry -TimeoutSec.
        preg_match_all('/Invoke-WebRequest\s+-Uri[^\r\n]*/', $script, $matches);
        // Four call sites in the source: bundle, VC++, the winget-deps loop
        // (one line, iterates over three URLs), and the .NET runtime.
        $this->assertCount(4, $matches[0]);
        foreach ($matches[0] as $line) {
            $this->assertStringContainsString('-TimeoutSec', $line, "every download must be time-bounded: {$line}");
        }

        // Repair-WinGetPackageManager has no -TimeoutSec of its own, so it
        // must run inside the Invoke-WithTimeout wrapper, not called bare.
        $wrapperPos = strpos($script, 'Invoke-WithTimeout');
        $repairPos = strpos($script, 'Repair-WinGetPackageManager -AllUsers');
        $this->assertNotFalse($wrapperPos);
        $this->assertNotFalse($repairPos);
        $this->assertGreaterThan($wrapperPos, $repairPos, 'Repair-WinGetPackageManager must be reached through the Invoke-WithTimeout wrapper');
    }

    /**
     * The direct-download provisioning path (plain HTTP downloads, each
     * capped) depends only on file downloads succeeding — not on Microsoft
     * Store services being reachable, which is what let the module-based
     * repair hang for hours in the field. It must run BEFORE that repair is
     * even attempted, so the common repair case never touches the risky path
     * at all.
     */
    public function test_direct_appx_provisioning_is_tried_before_the_module_based_repair(): void
    {
        $script = view('agent.install-script', [
            'project'   => $this->project,
            'serverUrl' => 'https://piodeploy.com',
            'binaryUrl' => 'https://piodeploy.com/download/agent/x/binary',
            'hasBundle' => true,
        ])->render();

        $directProvisionPos = strpos($script, 'Add-AppxProvisionedPackage -Online');
        $moduleRepairPos = strpos($script, 'Repair-WinGetPackageManager -AllUsers');

        $this->assertNotFalse($directProvisionPos);
        $this->assertNotFalse($moduleRepairPos);
        $this->assertLessThan($moduleRepairPos, $directProvisionPos, 'direct AppX provisioning must be tried before the Store-dependent module repair');
    }

    /**
     * The agent is a framework-dependent .NET 8 Worker Service (no
     * SelfContained/RuntimeIdentifier in the csproj), so a machine without
     * the .NET 8 Runtime already on it cannot start the service at all --
     * and nothing before Start-Service would have surfaced that. Checked
     * first, like winget, so the common case (already present) costs one
     * fast command rather than a needless download.
     */
    public function test_the_dotnet_runtime_is_checked_and_installed_if_missing(): void
    {
        $script = view('agent.install-script', [
            'project'   => $this->project,
            'serverUrl' => 'https://piodeploy.com',
            'binaryUrl' => 'https://piodeploy.com/download/agent/x/binary',
            'hasBundle' => true,
        ])->render();

        $this->assertStringContainsString('Test-DotNetRuntimeWorks', $script);
        $this->assertStringContainsString('Microsoft\.NETCore\.App 8\.', $script);
        $this->assertStringContainsString('https://aka.ms/dotnet/8.0/dotnet-runtime-win-x64.exe', $script);

        // Checked before the service is created, or a missing runtime is
        // discovered only when Start-Service already has nothing to start.
        $runtimeCheckPos = strpos($script, 'Test-DotNetRuntimeWorks');
        $serviceCreatePos = strpos($script, 'New-Service -Name $serviceName');
        $this->assertNotFalse($runtimeCheckPos);
        $this->assertNotFalse($serviceCreatePos);
        $this->assertLessThan($serviceCreatePos, $runtimeCheckPos, 'the runtime must be ensured before the service is created');
    }

    /**
     * A UTF-8 BOM once sat at the start of install-script.blade.php (an
     * editor artefact). Every enrolment path downloads this exact response
     * and feeds it straight into [scriptblock]::Create() -- with the BOM
     * present, PowerShell stops recognising #Requires and param() at all,
     * runs both as bare commands, and carries on with $ApiKey left empty:
     * the agent installs and the service starts, but it can never
     * authenticate, with nothing loud enough in the transcript to explain
     * why. Reproduced locally against a real PowerShell before this fix.
     * trim() does not catch this -- it is three raw bytes, not whitespace --
     * so the guard has to be a byte-level check on the actual response.
     */
    public function test_the_downloaded_install_script_never_starts_with_a_byte_order_mark(): void
    {
        $response = $this->get(route('agent.download', $this->project->download_token));

        $response->assertOk();
        $this->assertNotSame("\xEF\xBB\xBF", substr($response->getContent(), 0, 3), 'a leading BOM breaks #Requires/param() parsing in every install method');
        $this->assertStringStartsWith('#Requires -RunAsAdministrator', $response->getContent());
    }

    public function test_enrollment_script_service_strips_a_leading_bom_defensively(): void
    {
        $this->assertSame('#Requires -RunAsAdministrator', EnrollmentScriptService::stripBom("\xEF\xBB\xBF#Requires -RunAsAdministrator"));
        $this->assertSame('#Requires -RunAsAdministrator', EnrollmentScriptService::stripBom('#Requires -RunAsAdministrator'), 'a no-op when there is nothing to strip');
    }

    /**
     * Every method is rendered once and the tabs switch in the browser.
     * Round-tripping to the server for a presentational tab is what disturbed
     * the Alpine state holding the API key — the script kept its placeholder
     * and Copy stayed disabled with a valid key typed in.
     */
    public function test_every_method_is_rendered_so_tabs_need_no_server(): void
    {
        $html = $this->page()->html();

        foreach (['Group Policy computer startup script', 'Intune / Entra platform script'] as $script) {
            $this->assertStringContainsString($script, $html);
        }

        // Switching is client-side, and the block is left alone by Livewire.
        $this->assertStringContainsString('wire:ignore', $html);
        $this->assertStringContainsString('x-on:click="method =', $html);
        $this->assertStringNotContainsString('wire:click="select(', $html);
    }

    /**
     * The Copy button must read the script that is on screen.
     *
     * It used to copy a body baked into Alpine's x-data at first render.
     * Alpine never re-runs an x-data initialiser when Livewire swaps the DOM,
     * so switching tabs changed the visible script while Copy kept handing
     * out whichever one the page loaded with — always Group Policy.
     *
     * The server HTML was correct throughout, so no behavioural assertion can
     * catch this; the guard has to be that copy() reads the rendered element.
     */
    public function test_copy_reads_the_visible_script_rather_than_a_captured_one(): void
    {
        $html = $this->page()->html();

        // Each method's script has its own addressable block...
        foreach (array_keys(app(EnrollmentScriptService::class)->all($this->project, null)) as $method) {
            $this->assertStringContainsString('id="script-'.$method.'"', $html);
        }

        // ...copy() looks the block up by method rather than using a captured
        // body, and the untouched script is carried in the DOM.
        $this->assertStringContainsString("document.getElementById('script-' + method)", $html);
        $this->assertStringContainsString('data-body=', $html);
        $this->assertStringContainsString('$el.dataset.body', $html);

        // ...and the handler holds no script of its own.
        $handler = Str::between($html, 'copy(el, method) {', 'setTimeout');
        $this->assertNotSame('', trim($handler), 'the copy handler should be findable');
        $this->assertStringNotContainsString('PioDeploy agent', $handler,
            'copy() must not carry a script body — that is what went stale');
    }

    /** A script still holding the placeholder cannot install anything. */
    public function test_copy_is_refused_until_a_key_is_entered(): void
    {
        $html = $this->page()->html();

        $this->assertStringContainsString('Enter a key to copy', $html);
        $this->assertStringContainsString('x-bind:disabled="! entered || ! valid"', $html);
    }

    public function test_an_unknown_method_falls_back_rather_than_failing(): void
    {
        $this->page()->set('method', 'nonsense')->assertOk()->assertViewHas('selected', 'gpo');
    }

    public function test_the_page_is_tenant_scoped(): void
    {
        $otherClient = Client::factory()->create();
        $outsider = tap(
            User::factory()->create(['client_id' => $otherClient->id]),
            fn (User $u) => $u->assignRole(RoleEnum::Client->value)
        );

        Livewire::actingAs($outsider)
            ->test(ProjectEnrollment::class, ['project' => $this->project])
            ->assertForbidden();
    }

    public function test_the_route_renders_for_an_admin(): void
    {
        $this->actingAs($this->admin())
            ->get(route('projects.enrollment', $this->project))
            ->assertOk()
            ->assertSee('Enrol machines');
    }
}
