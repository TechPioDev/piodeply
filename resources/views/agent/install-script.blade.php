#Requires -RunAsAdministrator
<#
  PioDeploy agent installer - {{ $project->name }} ({{ $project->client->company_name }})
  Generated {{ now()->toDateString() }} by {{ config('app.name') }}.

  Usage (elevated PowerShell):
    .\install-piodeploy-agent.ps1 -ApiKey "pio_..."

  The API key is the project's agent key (shown once when the project was
  created or its key rotated). It is intentionally NOT embedded here.
#>
param(
    [Parameter(Mandatory = $true)] [string] $ApiKey,
    [string] $InstallDir = "$env:ProgramFiles\PioDeploy\Agent"
)

$ErrorActionPreference = 'Stop'
$serverUrl   = '{{ $serverUrl }}'
$bundleUrl   = '{{ $binaryUrl }}'
$serviceName = 'PioDeployAgent'

Write-Host "PioDeploy agent setup for project '{{ $project->name }}'"
Write-Host "Server: $serverUrl"

@if (! $hasBundle)
Write-Warning "The server has no published agent bundle yet."
Write-Warning "Ask your MSP to publish it (dotnet publish + upload), or install manually per the agent README."
exit 1
@else
# 1. Download the agent bundle
$tempZip = Join-Path $env:TEMP 'PioDeployAgent.zip'
Write-Host 'Downloading agent bundle...'
Invoke-WebRequest -Uri $bundleUrl -OutFile $tempZip -UseBasicParsing -TimeoutSec 120

# 2. Stop + remove any previous install
$existing = Get-Service -Name $serviceName -ErrorAction SilentlyContinue
if ($existing) {
    if ($existing.Status -eq 'Running') { Stop-Service $serviceName -Force }
    sc.exe delete $serviceName | Out-Null
    Start-Sleep -Seconds 2
}

# 3. Extract
New-Item -ItemType Directory -Force $InstallDir | Out-Null
Expand-Archive -Path $tempZip -DestinationPath $InstallDir -Force
Remove-Item $tempZip -Force

# 4. Configure
$configPath = Join-Path $InstallDir 'appsettings.json'
$config = Get-Content $configPath -Raw | ConvertFrom-Json
$config.PioDeploy.ServerUrl = $serverUrl
$config.PioDeploy.ApiKey = $ApiKey
$config | ConvertTo-Json -Depth 5 | Set-Content $configPath -Encoding UTF8

# 5. Preflight the machine so the agent can actually deploy software.
#    The agent runs as SYSTEM; two things have to work from that account or
#    every install silently fails. We check each, repair only what is broken,
#    verify, and never let a repair hiccup abort the agent install - the
#    portal's readiness banner reports anything that could not be fixed.
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12

# A stalled network call never times itself out in PowerShell by default -
# Invoke-WebRequest has no idle ceiling, and Microsoft's own winget-repair
# cmdlet below (Repair-WinGetPackageManager) is known, in the field, to sit
# for a very long time - an hour or more - waiting on Microsoft Store
# services when a machine has no working winget yet. A machine that already
# had winget working (every machine used to time this installer before) never
# exercises that path at all, which is why it never showed up as slow until a
# genuinely fresh physical machine hit it. Every potentially slow step from
# here down is either given an explicit -TimeoutSec, or - for the one call
# with no timeout parameter of its own - run in a background job with a hard
# wall-clock cap, so the worst case is minutes, never hours.
function Invoke-WithTimeout {
    param([Parameter(Mandatory)] [scriptblock] $Script, [int] $TimeoutSec = 120, [string] $What = 'step')
    $job = Start-Job -ScriptBlock $Script
    try {
        if (Wait-Job $job -Timeout $TimeoutSec) {
            Receive-Job $job -ErrorAction SilentlyContinue | Out-Null
            return ($job.State -eq 'Completed')
        }
        Write-Warning "$What did not finish within ${TimeoutSec}s; moving on."
        return $false
    } finally {
        Stop-Job $job -ErrorAction SilentlyContinue
        Remove-Job $job -Force -ErrorAction SilentlyContinue
    }
}

function Test-WingetWorks {
    # Resolve the real exe (the bare "winget" alias is not on SYSTEM's PATH),
    # then prove it actually launches - a fresh VM often has it present but
    # crashing on load with -1073741515 (missing VC++/UWP dependency).
    $exe = Get-ChildItem "$env:ProgramFiles\WindowsApps\Microsoft.DesktopAppInstaller_*_x64__8wekyb3d8bbwe\winget.exe" -ErrorAction SilentlyContinue |
        Sort-Object FullName -Descending | Select-Object -First 1 -ExpandProperty FullName
    if (-not $exe) { $exe = (Get-Command winget.exe -ErrorAction SilentlyContinue | Select-Object -First 1).Source }
    if (-not $exe) { return $false }
    try { & $exe --version *> $null; return ($LASTEXITCODE -eq 0) } catch { return $false }
}

# 5a. winget for the SYSTEM account, checked FIRST. Most machines already
#     have a working winget (it ships with modern Windows 10/11 and updates
#     itself), so the common case is one fast check and nothing else -- the
#     VC++ download+install below used to run unconditionally on every single
#     enrollment, adding a needless ~15-25MB fetch and a minute or more even
#     when it was a guaranteed no-op. It is only needed to REPAIR a winget
#     that is actually broken, so it now runs only on that path.
Write-Host 'Checking winget (Windows Package Manager)...'
if (Test-WingetWorks) {
    Write-Host 'winget is working.'
} else {
    Write-Host 'winget is missing or broken for this account; repairing for all users...'

    # Visual C++ desktop runtime. Many app installers (Chrome) - and winget
    # itself - fail to launch without it: exit -1073741515 / 0xC0000135
    # (STATUS_DLL_NOT_FOUND). The redist is idempotent (no-ops when current);
    # only worth fetching now that winget has actually proven broken.
    try {
        Write-Host 'Ensuring Visual C++ runtime...'
        $vc = Join-Path $env:TEMP 'vc_redist.x64.exe'
        Invoke-WebRequest -Uri 'https://aka.ms/vs/17/release/vc_redist.x64.exe' -OutFile $vc -UseBasicParsing -TimeoutSec 90
        $vcProc = Start-Process -FilePath $vc -ArgumentList '/install', '/quiet', '/norestart' -Wait -PassThru
        Remove-Item $vc -Force -ErrorAction SilentlyContinue
        if ($vcProc.ExitCode -in 0, 1638, 3010) { Write-Host 'Visual C++ runtime present.' }
        else { Write-Warning "VC++ runtime installer returned $($vcProc.ExitCode)." }
    } catch {
        Write-Warning "Could not ensure the Visual C++ runtime: $($_.Exception.Message)"
    }

    # Primary: provision winget and its VCLibs / UI.Xaml dependencies straight
    # from Microsoft as a handful of plain, bounded HTTP downloads. Tried
    # FIRST (it used to be the fallback) because it depends on nothing but
    # file downloads, each capped below - not on Microsoft Store services
    # being reachable and responsive, which is what made the other approach
    # capable of hanging for hours on a machine that actually needs repairing.
    try {
        $wtmp = Join-Path $env:TEMP 'pd-winget'
        New-Item -ItemType Directory -Force $wtmp | Out-Null
        # Parallel lists (no @() literal, which Blade could misread).
        $depUrls  = 'https://aka.ms/Microsoft.VCLibs.x64.14.00.Desktop.appx',
                    'https://github.com/microsoft/microsoft-ui-xaml/releases/download/v2.8.6/Microsoft.UI.Xaml.2.8.x64.appx',
                    'https://aka.ms/getwinget'
        $depFiles = 'vclibs.appx', 'uixaml.appx', 'winget.msixbundle'
        for ($k = 0; $k -lt $depUrls.Count; $k++) {
            $p = Join-Path $wtmp $depFiles[$k]
            Invoke-WebRequest -Uri $depUrls[$k] -OutFile $p -UseBasicParsing -TimeoutSec 90
            try { Add-AppxProvisionedPackage -Online -PackagePath $p -SkipLicense -ErrorAction Stop | Out-Null }
            catch { Write-Warning "Could not provision $($depFiles[$k]): $($_.Exception.Message)" }
        }
        Remove-Item $wtmp -Recurse -Force -ErrorAction SilentlyContinue
    } catch {
        Write-Warning "Direct winget provisioning failed: $($_.Exception.Message)"
    }

    # Fallback: Microsoft's own module-based repair, tried only if the direct
    # downloads above did not already fix it, and hard-capped at two minutes -
    # Repair-WinGetPackageManager has no timeout of its own and has been seen,
    # in the field, to hang far longer than that waiting on Store services on
    # exactly the kind of machine that reaches this line.
    if (-not (Test-WingetWorks)) {
        Write-Host 'Direct provisioning did not finish the job; trying the module-based repair (capped at 2 minutes)...'
        Invoke-WithTimeout -TimeoutSec 120 -What 'winget module repair' -Script {
            Install-PackageProvider -Name NuGet -Force -ErrorAction Stop | Out-Null
            Set-PSRepository -Name PSGallery -InstallationPolicy Trusted -ErrorAction SilentlyContinue
            Install-Module -Name Microsoft.WinGet.Client -Force -Scope AllUsers -ErrorAction Stop
            Import-Module Microsoft.WinGet.Client -ErrorAction Stop
            Repair-WinGetPackageManager -AllUsers -Latest -ErrorAction Stop
        } | Out-Null
    }

    if (Test-WingetWorks) { Write-Host 'winget repaired.' }
    else { Write-Warning 'winget could not be made ready; the agent will still run and the portal will flag this machine.' }
}

# 5c. .NET 8 Runtime. The agent is a framework-dependent .NET 8 Worker
#     Service, so it needs this present system-wide or the service fails to
#     start -- silently, from this script's point of view, since a process
#     that cannot find its runtime exits before Start-Service below can
#     report anything useful. Checked first so a machine that already has it
#     (most current ones do) pays nothing beyond one fast command.
function Test-DotNetRuntimeWorks {
    $dotnet = Get-Command dotnet.exe -ErrorAction SilentlyContinue
    if (-not $dotnet) { return $false }
    try { return ((& $dotnet.Source --list-runtimes 2>$null) -match '^Microsoft\.NETCore\.App 8\.').Count -gt 0 }
    catch { return $false }
}

Write-Host 'Checking .NET 8 Runtime...'
if (Test-DotNetRuntimeWorks) {
    Write-Host '.NET 8 Runtime is present.'
} else {
    Write-Host '.NET 8 Runtime missing; installing...'
    try {
        $dotnetInstaller = Join-Path $env:TEMP 'dotnet-runtime-8-x64.exe'
        Invoke-WebRequest -Uri 'https://aka.ms/dotnet/8.0/dotnet-runtime-win-x64.exe' -OutFile $dotnetInstaller -UseBasicParsing -TimeoutSec 120
        $dotnetProc = Start-Process -FilePath $dotnetInstaller -ArgumentList '/install', '/quiet', '/norestart' -Wait -PassThru
        Remove-Item $dotnetInstaller -Force -ErrorAction SilentlyContinue
        if ($dotnetProc.ExitCode -in 0, 3010) { Write-Host '.NET 8 Runtime installed.' }
        else { Write-Warning ".NET 8 Runtime installer returned $($dotnetProc.ExitCode); the agent service may fail to start." }
    } catch {
        Write-Warning "Could not install the .NET 8 Runtime: $($_.Exception.Message). The agent service will likely fail to start until this machine has it."
    }
}

# 6. Install + start the service
New-Service -Name $serviceName `
    -BinaryPathName (Join-Path $InstallDir 'PioDeployAgent.exe') `
    -DisplayName 'PioDeploy Agent' `
    -Description 'TechPio PioDeploy software deployment agent.' `
    -StartupType Automatic | Out-Null

# Windows restarts the service by itself if it CRASHES. failureflag=1 makes
# that apply to any non-zero exit too, not just a hard crash.
sc.exe failure $serviceName reset= 86400 actions= restart/60000/restart/60000/restart/60000 | Out-Null
sc.exe failureflag $serviceName 1 | Out-Null

# 7. Watchdog - the piece that keeps the agent alive no matter what.
#    Recovery actions only cover an unexpected exit; they do NOT restart a
#    service someone STOPS by hand, or one left stopped by a bad update. A
#    SYSTEM scheduled task every 2 minutes closes that gap: if the service is
#    not running, it starts it. Stopping the agent therefore does nothing
#    lasting - it is back within two minutes - without blocking the agent's
#    own controlled stops for self-update (the update helper restarts it far
#    faster than the watchdog interval). This is exactly what would have kept
#    a fleet of older agents from going dark.
#    "net start" on an already-running service is a harmless no-op error, so
#    the task needs NO embedded quotes - schtasks /TR quoting silently broke
#    the quoted-powershell variant (task never registered).
#
#    Battery: schtasks-created tasks default to "start only on AC power" and
#    "stop when switching to battery" - on a laptop running on battery the
#    watchdog would simply NEVER fire (found the hard way: a keeper task
#    silently skipped every slot until the charger was plugged in).
#    Set-PioTaskBatteryProof strips those conditions from every task we make.
function Set-PioTaskBatteryProof([string]$name) {
    try {
        $s = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries `
             -MultipleInstances IgnoreNew -ExecutionTimeLimit ([TimeSpan]::Zero)
        Set-ScheduledTask -TaskName $name -Settings $s -ErrorAction Stop | Out-Null
    } catch { Write-Warning "Could not set power conditions on task $name : $($_.Exception.Message)" }
}
$watchdogName = 'PioDeployAgentWatchdog'
cmd.exe /c "schtasks /Delete /TN $watchdogName /F >nul 2>&1"
schtasks.exe /Create /TN $watchdogName /TR "net start $serviceName" /SC MINUTE /MO 2 /RU SYSTEM /RL HIGHEST /F | Out-Null
if ($LASTEXITCODE -ne 0) { Write-Warning "Watchdog task could not be created (schtasks exit $LASTEXITCODE)." }
Set-PioTaskBatteryProof $watchdogName

# 8. Tray status indicator (per-user). The service runs as SYSTEM in
#    session 0 and cannot draw UI, so a tiny PowerShell helper runs in each
#    logged-in user's session, reads the status file the service writes, and
#    shows a system-tray icon with agent health. Read-only - it changes
#    nothing, it just lets the user see the agent is present and healthy.
$trayDir = Join-Path $env:ProgramData 'PioDeploy'
New-Item -ItemType Directory -Force $trayDir | Out-Null
$trayScript = Join-Path $trayDir 'pio-tray.ps1'
@'
# PioDeploy tray helper - status only. Reads C:\ProgramData\PioDeploy\status.json.
# Crash log: %ProgramData%\PioDeploy\logs\tray.log (why-did-it-die evidence).
$trayLog = Join-Path $env:ProgramData 'PioDeploy\logs\tray.log'
# Single instance per session: the keeper task fires every few minutes and
# must be a silent no-op when the tray is already up (else: duplicate icons).
$script:pioMutex = New-Object System.Threading.Mutex($false, 'Local\PioDeployTray')
if (-not $script:pioMutex.WaitOne(0)) { exit }
try {
"$(Get-Date -Format s)  tray starting (pid $PID)" | Out-File $trayLog -Append -Encoding utf8
# At logon the taskbar may not exist yet; a NotifyIcon created too early
# never shows. Give explorer a moment before creating the icon.
Start-Sleep -Seconds 8
Add-Type -AssemblyName System.Windows.Forms, System.Drawing
$ErrorActionPreference = 'SilentlyContinue'
$statusPath = Join-Path $env:ProgramData 'PioDeploy\status.json'

# Compose at the EXACT size the tray renders (DPI-aware SmallIconSize):
# building at 32px and letting the shell downscale produced a garbled mess.
$iconSize = [System.Windows.Forms.SystemInformation]::SmallIconSize
$w = $iconSize.Width; $h = $iconSize.Height

# Brand icon: pio.ico ships in the agent bundle; fall back to a stock shield.
$baseIcon = [System.Drawing.SystemIcons]::Shield
foreach ($cand in @(
    (Join-Path $env:ProgramFiles 'PioDeploy\Agent\pio.ico'),
    (Join-Path $env:ProgramData  'PioDeploy\pio.ico'))) {
    if (Test-Path $cand) { try { $baseIcon = New-Object System.Drawing.Icon($cand, $w, $h); break } catch {} }
}

# Status dot composed onto the brand icon: GREEN = agent healthy (service
# running and checking in), RED = service stopped/disabled or not reporting.
function New-StatusIcon($base, $color) {
    $bmp = New-Object System.Drawing.Bitmap $w, $h
    $g = [System.Drawing.Graphics]::FromImage($bmp)
    $g.SmoothingMode = [System.Drawing.Drawing2D.SmoothingMode]::AntiAlias
    $g.DrawIcon($base, (New-Object System.Drawing.Rectangle 0, 0, $w, $h))
    $d = [int][math]::Floor($w / 2)
    $g.FillEllipse((New-Object System.Drawing.SolidBrush ([System.Drawing.Color]::White)), ($w - $d), ($h - $d), $d, $d)
    $g.FillEllipse((New-Object System.Drawing.SolidBrush $color), ($w - $d + 1), ($h - $d + 1), ($d - 2), ($d - 2))
    $g.Dispose()
    [System.Drawing.Icon]::FromHandle($bmp.GetHicon())
}
# Prefer the pre-built status icons shipped in the agent bundle (crisp,
# hand-packed frames); the runtime composition above is only a fallback -
# GDI icon conversion mangles PNG-frame icons on some systems.
$icoGreen = $null; $icoRed = $null
foreach ($root in @(
    (Join-Path $env:ProgramFiles 'PioDeploy\Agent'),
    (Join-Path $env:ProgramData  'PioDeploy'))) {
    $gp = Join-Path $root 'pio-green.ico'
    $rp = Join-Path $root 'pio-red.ico'
    if ((Test-Path $gp) -and (Test-Path $rp)) {
        try {
            $icoGreen = New-Object System.Drawing.Icon($gp, $w, $h)
            $icoRed   = New-Object System.Drawing.Icon($rp, $w, $h)
            break
        } catch { $icoGreen = $null; $icoRed = $null }
    }
}
if (-not $icoGreen -or -not $icoRed) {
    $icoGreen = New-StatusIcon $baseIcon ([System.Drawing.Color]::FromArgb(34, 197, 94))
    $icoRed   = New-StatusIcon $baseIcon ([System.Drawing.Color]::FromArgb(239, 68, 68))
}

$ni = New-Object System.Windows.Forms.NotifyIcon
$ni.Icon = $icoGreen
$ni.Text = 'PioDeploy Agent'
$ni.Visible = $true
$menu = New-Object System.Windows.Forms.ContextMenuStrip
$miStatus  = $menu.Items.Add('Checking...'); $miStatus.Enabled = $false
$miVersion = $menu.Items.Add('');            $miVersion.Enabled = $false
$miSeen    = $menu.Items.Add('');            $miSeen.Enabled = $false
$miPending = $menu.Items.Add('');            $miPending.Enabled = $false
$menu.Items.Add('-') | Out-Null
# Sync now: drops a flag file the agent polls during its wait, forcing an
# immediate check-in - pending policies and deployments apply right away
# instead of at the next cycle. Requires agent 1.4.21+.
$miSync = $menu.Items.Add('Sync now')
$miSync.add_Click({
    try {
        New-Item -ItemType File -Force -Path (Join-Path $env:ProgramData 'PioDeploy\force-checkin.flag') | Out-Null
        $miStatus.Text = 'Status: sync requested...'
    } catch { $miStatus.Text = 'Status: sync request failed' }
})
$miLogs = $menu.Items.Add('Open logs folder')
$miLogs.add_Click({ Start-Process (Join-Path $env:ProgramData 'PioDeploy\logs') })
$ni.ContextMenuStrip = $menu

$timer = New-Object System.Windows.Forms.Timer
$timer.Interval = 30000
$script:expPid = (Get-Process explorer -ErrorAction SilentlyContinue | Select-Object -First 1).Id
$refresh = {
    try {
        # Explorer restart wipes tray icons of running apps; re-register ours
        # by toggling visibility whenever explorer's PID changes.
        $exp = (Get-Process explorer -ErrorAction SilentlyContinue | Select-Object -First 1).Id
        if ($exp -and $exp -ne $script:expPid) {
            $script:expPid = $exp
            $ni.Visible = $false
            $ni.Visible = $true
        }
        $svcRunning = (Get-Service PioDeployAgent -ErrorAction SilentlyContinue).Status -eq 'Running'
        $online = $false
        $ver = ''
        $brand = 'PioDeploy Agent'
        $trayOn = $true
        if (Test-Path $statusPath) {
            $s = Get-Content $statusPath -Raw | ConvertFrom-Json
            $seen = [datetime]::Parse($s.checked_in_utc).ToUniversalTime()
            $mins = [math]::Round(([datetime]::UtcNow - $seen).TotalMinutes)
            $online = $mins -lt 5
            $ver = $s.version
            $miVersion.Text = "Version: $($s.version)" + $(if ($s.latest -and $s.latest -ne $s.version) { " (updating to $($s.latest))" } else { '' })
            $miSeen.Text    = "Last check-in: $mins min ago"
            $miPending.Text = "Pending updates: $($s.pending_jobs)"
            if ($s.PSObject.Properties['tray_name'] -and $s.tray_name) { $brand = [string]$s.tray_name }
            if ($s.PSObject.Properties['tray_enabled']) { $trayOn = [bool]$s.tray_enabled }
        }
        $healthy = $svcRunning -and $online
        $ni.Icon = if ($healthy) { $icoGreen } else { $icoRed }
        $miStatus.Text = if (-not $svcRunning) { 'Status: agent service STOPPED' }
                         elseif ($online)      { 'Status: Online' }
                         else                  { 'Status: Offline (not reporting)' }
        # Per-client branding: name from the portal, visibility switch too.
        $text = "$brand - " + $(if (-not $svcRunning) { 'service stopped' } elseif ($online) { "online, v$ver" } else { 'offline' })
        $ni.Text = $(if ($text.Length -gt 63) { $text.Substring(0, 63) } else { $text })
        $ni.Visible = $trayOn
    } catch { $miStatus.Text = 'Status: unknown' }
}
& $refresh
$timer.add_Tick($refresh)
$timer.Start()
$ctx = New-Object System.Windows.Forms.ApplicationContext
[System.Windows.Forms.Application]::Run($ctx)
"$(Get-Date -Format s)  tray exited normally" | Out-File $trayLog -Append -Encoding utf8
} catch {
"$(Get-Date -Format s)  tray CRASHED: $($_.Exception.Message)" | Out-File $trayLog -Append -Encoding utf8
}
'@ | Set-Content $trayScript -Encoding UTF8

# Launcher: the scheduled tasks run THIS one-second VBScript, which spawns
# the tray hidden+detached and exits. Two reasons it is a VBS under wscript:
# (1) a forever-running tray must never BE the task - Task Scheduler stops
# long-running instances at its own lifecycle points, which kept killing
# the icon; (2) wscript is a windowless host, so users never see the
# console flash that powershell.exe shows even with -WindowStyle Hidden
# (a cmd-like popup every keeper cycle, reported from the field).
$trayLauncher = Join-Path $trayDir 'pio-tray-launch.vbs'
@'
CreateObject("WScript.Shell").Run "powershell.exe -NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File C:\ProgramData\PioDeploy\pio-tray.ps1", 0, False
'@ | Set-Content $trayLauncher -Encoding ASCII

# NO quotes inside /TR (the path has no spaces): schtasks mangles embedded
# quotes and silently refuses the task - the same bug that once left the
# watchdog unregistered. Exit codes are checked, not swallowed.
$trayCmd  = "wscript.exe $trayLauncher"
$trayTask = 'PioDeployAgentTray'
cmd.exe /c "schtasks /Delete /TN $trayTask /F >nul 2>&1"
# /RU Users + ONLOGON: runs in whichever user logs on, in their session.
schtasks.exe /Create /TN $trayTask /TR $trayCmd /SC ONLOGON /RU Users /RL LIMITED /F | Out-Null
if ($LASTEXITCODE -ne 0) { Write-Warning "Tray logon task not created (schtasks exit $LASTEXITCODE)." }
Set-PioTaskBatteryProof $trayTask
# Keeper: every 5 minutes, relaunch the tray if someone closed it. The
# mutex inside pio-tray.ps1 makes this a no-op while the tray is running.
$keeperTask = 'PioDeployAgentTrayKeeper'
cmd.exe /c "schtasks /Delete /TN $keeperTask /F >nul 2>&1"
schtasks.exe /Create /TN $keeperTask /TR $trayCmd /SC MINUTE /MO 5 /RU Users /RL LIMITED /F | Out-Null
if ($LASTEXITCODE -ne 0) { Write-Warning "Tray keeper task not created (schtasks exit $LASTEXITCODE)." }
Set-PioTaskBatteryProof $keeperTask
# Start it now for the user running the installer, without waiting for a re-login.
cmd.exe /c "schtasks /Run /TN $trayTask >nul 2>&1"

Start-Service $serviceName

Write-Host 'PioDeploy agent installed and started (with self-healing watchdog).'
Write-Host "Logs: $env:ProgramData\PioDeploy\logs"
@endif
