<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-slate-800 leading-tight">
            {{ __('Enrol machines') }}
            <span class="text-slate-400 font-normal">— {{ $project->name }}</span>
        </h2>
    </x-slot>

    {{-- The key is deliberately outside Livewire. Public properties are
         serialised into the page and posted on every update, which is no place
         for a credential that installs software as SYSTEM across a fleet. The
         script renders with a placeholder and the browser swaps it in; the key
         never leaves this tab. --}}
    <div class="py-12"
         x-data="{
             key: '',
             method: @js($selected),
             get entered() { return this.key.trim() !== '' },
             get valid() { return new RegExp(@js($keyPattern)).test(this.key.trim()) },
             fill(body) {
                 return (this.entered && this.valid)
                     ? body.replaceAll(@js($placeholder), this.key.trim())
                     : body;
             },
             /**
              * Copy exactly what is on screen.
              *
              * This used to copy @js($current['body']) — the script body baked
              * into x-data at first render. Alpine never re-runs an x-data
              * initialiser when Livewire swaps the DOM, so switching tabs
              * changed the visible script while the button kept handing out
              * the one the page happened to load with. Reading the rendered
              * block instead cannot drift from what the operator sees.
              */
             copy(el, method) {
                 if (! this.entered || ! this.valid) {
                     return; // the button is disabled; nothing safe to copy
                 }

                 // Read the block for THIS method straight from the DOM, and
                 // apply fill() again on the way out — so the clipboard holds
                 // a runnable script even if the on-screen substitution has
                 // not been applied for any reason.
                 const body = document.getElementById('script-' + method).dataset.body;

                 navigator.clipboard.writeText(this.fill(body));
                 el.textContent = 'Copied';
                 setTimeout(() => el.textContent = 'Copy', 1500);
             },
         }">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-4">

            @if (session('status'))
                <div class="rounded-md bg-green-50 border border-green-200 p-3 text-sm text-green-700" role="status">
                    {{ session('status') }}
                </div>
            @endif

            @can('rotateApiKey', $project)
                <div class="pd-card p-6 space-y-3">
                    <div class="flex items-center justify-between">
                        <h3 class="text-sm font-semibold text-slate-800">API keys</h3>
                        @php $revokedCount = $apiKeys->whereNotNull('revoked_at')->count(); @endphp
                        <span class="text-xs text-slate-400">
                            {{ $apiKeys->whereNull('revoked_at')->count() }} active
                            @if ($revokedCount > 0) &middot; {{ $revokedCount }} revoked @endif
                        </span>
                    </div>
                    <p class="text-xs text-slate-500">
                        A project can hold several keys — one per site, RMM, or rollout wave. Creating a key never
                        affects machines enrolled with another; revoking one stops <em>only</em> the machines using it.
                        Each key's value is shown once, at creation.
                    </p>

                    @if ($revealedKey !== null)
                        <div class="rounded-md bg-teal-50 border border-teal-200 p-3 space-y-1" role="status">
                            <p class="text-xs font-semibold text-teal-800">New key — copy it now, it will not be shown again:</p>
                            <code class="block font-mono text-sm text-teal-900 break-all select-all">{{ $revealedKey }}</code>
                            <button type="button" wire:click="dismissKey" class="text-xs pd-action">I've copied it — hide</button>
                        </div>
                    @endif

                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs text-slate-400 uppercase">
                                <th class="py-1 pr-3">Label</th><th class="py-1 pr-3">Key</th>
                                <th class="py-1 pr-3">Created</th><th class="py-1 pr-3">Created by</th>
                                <th class="py-1 pr-3">Last used</th><th class="py-1"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($apiKeys as $apiKey)
                                <tr class="border-t border-slate-100 {{ $apiKey->revoked_at ? 'opacity-50' : '' }}">
                                    <td class="py-1.5 pr-3">{{ $apiKey->label }}</td>
                                    <td class="py-1.5 pr-3 font-mono text-xs">{{ $apiKey->key_prefix }}…</td>
                                    <td class="py-1.5 pr-3 text-xs text-slate-500">{{ $apiKey->created_at->format('Y-m-d') }}</td>
                                    <td class="py-1.5 pr-3 text-xs text-slate-500">{{ $apiKey->creator?->name ?? '—' }}</td>
                                    <td class="py-1.5 pr-3 text-xs text-slate-500">{{ $apiKey->last_used_at?->diffForHumans() ?? 'never' }}</td>
                                    <td class="py-1.5 text-right">
                                        @if ($apiKey->revoked_at)
                                            <span class="text-xs text-slate-400">revoked {{ $apiKey->revoked_at->format('Y-m-d') }}</span>
                                        @else
                                            <button type="button" wire:click="revokeKey({{ $apiKey->id }})"
                                                wire:confirm="Revoke key {{ $apiKey->key_prefix }}…? Every machine enrolled with THIS key stops authenticating until re-enrolled with another. Machines on other keys are unaffected."
                                                class="text-xs text-rose-600 hover:text-rose-700 font-medium">Revoke</button>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>

                    <div class="flex gap-2">
                        <input type="text" wire:model="newKeyLabel" placeholder="Label (e.g. London office, NinjaOne)"
                               class="block w-64 text-sm border-slate-300 focus:border-teal-500 focus:ring-teal-500 rounded-md shadow-sm">
                        <button type="button" wire:click="createKey"
                                class="inline-flex items-center px-4 py-2 bg-teal-700 border border-transparent rounded-lg font-semibold text-xs text-white uppercase tracking-widest hover:bg-teal-800">
                            New key
                        </button>
                    </div>
                </div>
            @endcan

            <div class="pd-card p-6">
                <label for="apiKey" class="block text-sm font-medium text-slate-700">{{ project_term() }} API key</label>
                <p class="text-xs text-slate-500 mt-1">
                    Paste a key for <strong>{{ $project->name }}</strong> and it drops into the scripts below.
                    Keys are shown once at creation — PioDeploy stores only a hash and cannot show them
                    again. Lost it? Create a new key above; machines enrolled with existing keys are unaffected.
                </p>
                <input id="apiKey" type="text" x-model="key" autocomplete="off" spellcheck="false"
                       placeholder="{{ $placeholder }}"
                       class="mt-2 block w-full font-mono text-sm border-slate-300 focus:border-teal-500 focus:ring-teal-500 rounded-md shadow-sm">

                <p x-cloak x-show="entered && ! valid"
                   class="mt-2 text-xs text-red-700 bg-red-50 border border-red-200 rounded-md p-2">
                    That does not look like a {{ project_term_lower() }} key. A key is 8–128 characters of letters, numbers,
                    <code>-</code> and <code>_</code> — nothing else. The script below keeps the placeholder rather
                    than embed something unexpected in a script that runs as SYSTEM on every machine it reaches.
                    If this came from an email or a message, check where it came from.
                </p>
                <p x-cloak x-show="! entered"
                   class="mt-2 text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded-md p-2">
                    No key entered — the scripts below carry a placeholder and will not run until you replace it.
                </p>
                <p x-cloak x-show="entered && valid" class="mt-2 text-xs text-slate-400">
                    Filled in below. Your key stays in this browser tab — it is never sent to the server.
                </p>
            </div>

            {{-- Every method is rendered once and switched in the browser.
                 Tabs used to round-trip to the server, and that re-render
                 disturbed the Alpine state holding the key: the script kept
                 its placeholder and Copy stayed disabled even with a valid key
                 typed in. A tab switch is presentation — it has no business
                 touching the server, and now nothing can churn the key. --}}
            <div class="pd-card p-6 space-y-4" wire:ignore>
                <div class="flex flex-wrap gap-2" role="tablist" aria-label="Enrollment method">
                    @foreach ($methods as $key => $method)
                        <button type="button" role="tab"
                                x-on:click="method = @js($key)"
                                x-bind:aria-selected="method === @js($key) ? 'true' : 'false'"
                                x-bind:class="method === @js($key)
                                    ? 'bg-teal-700 border-teal-700 text-white'
                                    : 'bg-white border-slate-300 text-slate-600 hover:border-teal-400'"
                                class="px-3 py-1.5 rounded-full text-sm border transition">
                            {{ $method['label'] }}
                        </button>
                    @endforeach
                </div>

                @foreach ($methods as $key => $method)
                    <div x-show="method === @js($key)" x-cloak class="space-y-4">
                        @if ($key === 'gpo')
                            <div class="text-sm text-slate-600 bg-slate-50 border border-slate-200 rounded-md p-3">
                                <p class="font-medium text-slate-700">Runs as SYSTEM at boot — nobody has to log in.</p>
                                <p class="mt-1">
                                    Save it to <code class="font-mono text-xs">\\yourdomain\NETLOGON\PioDeploy\</code>, then in
                                    <strong>gpmc.msc</strong>: create a GPO on the target OU → Edit → Computer Configuration →
                                    Policies → Windows Settings → Scripts (Startup/Shutdown) → <strong>Startup</strong> →
                                    PowerShell Scripts → Add. Then <code class="font-mono text-xs">gpupdate /force</code> and reboot.
                                </p>
                                <p class="mt-1 text-slate-500">
                                    Safe every boot: it exits immediately when the agent is already at
                                    {{ \App\Services\EnrollmentScriptService::CURRENT_AGENT_VERSION }} or newer, and upgrades it when it is not.
                                </p>
                            </div>
                        @endif

                        <div>
                            <div class="flex items-center justify-between mb-1 gap-3">
                                <span class="text-xs font-mono text-slate-500">{{ $method['filename'] }}</span>

                                {{-- Copying a script that still carries the placeholder
                                     hands the operator something that cannot run, and
                                     the failure only shows up on the target machine.
                                     Refuse at the button rather than at the console. --}}
                                <button type="button"
                                        class="text-xs pd-link disabled:opacity-40 disabled:cursor-not-allowed disabled:no-underline"
                                        x-bind:disabled="! entered || ! valid"
                                        x-bind:title="entered ? (valid ? 'Copy this script' : 'That key does not look like a project key') : 'Paste your project API key above first'"
                                        x-on:click="copy($el, @js($key))"
                                        x-text="(! entered || ! valid) ? 'Enter a key to copy' : 'Copy'">Copy</button>
                            </div>

                            {{-- The untouched script stays in data-body, so what is
                                 shown and what is copied both derive from the DOM
                                 rather than a value captured at page load. --}}
                            <pre id="script-{{ $key }}"
                                 data-body="{{ $method['body'] }}"
                                 x-effect="$el.textContent = fill($el.dataset.body)"
                                 class="bg-slate-900 text-slate-100 rounded-lg p-4 overflow-x-auto text-xs leading-relaxed">{{ $method['body'] }}</pre>
                        </div>
                    </div>
                @endforeach
            </div>

            {{-- Fresh VMs are the machines that used to fail: a clean Windows
                 image often lacks the VC++ runtime and a working winget, which
                 is what the installer now repairs on its own. Say so here so an
                 operator enrolling a VM knows there is nothing extra to do. --}}
            <div class="pd-card p-4 border-emerald-200 bg-emerald-50/50 flex gap-3">
                <svg class="h-5 w-5 text-emerald-600 shrink-0 mt-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 12l2 2 4-4"/><circle cx="12" cy="12" r="10"/></svg>
                <div class="text-sm text-slate-700 space-y-1">
                    <p class="font-semibold text-emerald-800">Fresh machines &amp; VMs are prepared automatically.</p>
                    <p>
                        A clean Windows image — especially a new VM — usually lacks the <strong>Visual C++ runtime</strong>
                        and a working <strong>winget</strong> for the SYSTEM account. The installer checks both and repairs
                        them before the agent starts, so a bare VM enrols with no manual prep. This is what previously
                        caused installs to fail with exit <code class="font-mono text-xs">-1073741515</code>.
                    </p>
                    <p class="text-slate-500">
                        Anything that still can't be fixed shows on the machine's page as a
                        <strong>“Not ready to deploy”</strong> banner naming the exact remedy — so a VM is never silently broken.
                    </p>
                </div>
            </div>

            <p class="text-xs text-slate-500">
                Every method installs the same agent and enrols it into <strong>{{ $project->name }}</strong>.
                Machines appear under <a href="{{ route('computers.index') }}" class="pd-link">Computers</a> within a
                minute of the agent starting.
            </p>
        </div>
    </div>
</div>
