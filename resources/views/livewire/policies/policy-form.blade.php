<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-slate-800 leading-tight">
            {{ $policy ? 'Edit '.policy_term_lower() : 'New '.policy_term() }}
        </h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <form wire:submit="save">
                <div class="grid grid-cols-1 lg:grid-cols-5 gap-6 items-start">

                    {{-- ─────────── Step 1 · Software (creation: pick many) ─────────── --}}
                    <div class="lg:col-span-3 pd-card p-6 space-y-4">
                        <div>
                            <h3 class="text-sm font-semibold text-slate-700 uppercase tracking-wider">1 · Software</h3>
                            <p class="text-xs text-slate-500 mt-1">
                                @if ($policy)
                                    This policy governs one package.
                                @else
                                    Add every package this rule should cover — each one becomes its own
                                    policy row, so compliance stays per-app.
                                @endif
                            </p>
                        </div>

                        @if ($policy)
                            <div class="rounded-md border border-slate-200 bg-slate-50 px-4 py-3 text-sm font-medium text-slate-800">
                                {{ $policy->package->name }}
                            </div>
                        @else
                            {{-- Selected chips --}}
                            @if ($selectedPackages->isNotEmpty())
                                <div class="flex flex-wrap gap-2">
                                    @foreach ($selectedPackages as $selected)
                                        <span class="inline-flex items-center gap-1.5 rounded-full bg-teal-50 border border-teal-200 text-teal-800 text-sm pl-3 pr-1.5 py-1">
                                            {{ $selected->name }}
                                            <button type="button" wire:click="removePackage({{ $selected->id }})"
                                                    class="h-5 w-5 grid place-content-center rounded-full text-teal-600 hover:bg-teal-100 hover:text-rose-600 font-bold"
                                                    title="Remove {{ $selected->name }}">−</button>
                                        </span>
                                    @endforeach
                                    <span class="self-center text-xs text-slate-400">{{ $selectedPackages->count() }} selected</span>
                                </div>
                            @endif

                            {{-- Search + add list --}}
                            <div>
                                <input type="search" wire:model.live.debounce.250ms="packageSearch"
                                       placeholder="Search software to add…"
                                       class="block w-full border-slate-300 focus:border-teal-500 focus:ring-teal-500 rounded-md shadow-sm text-sm">
                                <div class="mt-2 max-h-72 overflow-y-auto divide-y divide-slate-100 border border-slate-200 rounded-md">
                                    @forelse ($packageChoices as $choice)
                                        <div class="flex items-center justify-between px-4 py-2 hover:bg-slate-50">
                                            <span class="text-sm text-slate-700">
                                                {{ $choice->name }}
                                                <span class="text-xs text-slate-400">({{ $choice->installer_type->label() }})</span>
                                            </span>
                                            <button type="button" wire:click="addPackage({{ $choice->id }})"
                                                    class="h-7 w-7 grid place-content-center rounded-full bg-teal-600 text-white text-base font-bold hover:bg-teal-700"
                                                    title="Add {{ $choice->name }}">+</button>
                                        </div>
                                    @empty
                                        <p class="px-4 py-6 text-sm text-slate-500 text-center">
                                            {{ trim($packageSearch) !== '' ? 'Nothing matches that search.' : 'Everything is already selected.' }}
                                        </p>
                                    @endforelse
                                </div>
                            </div>
                            <x-input-error for="packageIds" class="mt-1" />
                            <x-input-error for="package_id" class="mt-1" />
                        @endif
                    </div>

                    {{-- ─────────── Steps 2-3 · Where & How ─────────── --}}
                    <div class="lg:col-span-2 space-y-6">
                        <div class="pd-card p-6 space-y-4">
                            <h3 class="text-sm font-semibold text-slate-700 uppercase tracking-wider">2 · Where &amp; what</h3>

                            <div>
                                <x-label value="Target type" />
                                <div class="mt-1 flex flex-wrap gap-4">
                                    @foreach (['project' => project_term(), 'group' => 'Device group', 'computer' => 'Specific machine'] as $value => $label)
                                        <label class="inline-flex items-center gap-1.5 text-sm text-slate-600 select-none">
                                            <input type="radio" wire:model.live="scope_type" value="{{ $value }}"
                                                   class="border-slate-300 text-teal-600 focus:ring-teal-500">
                                            {{ $label }}
                                        </label>
                                    @endforeach
                                </div>
                            </div>

                            @if ($scope_type === 'project')
                                <div>
                                    <x-label for="project_id" :value="project_term()" />
                                    <select id="project_id" wire:model="project_id"
                                            class="mt-1 block w-full border-slate-300 focus:border-teal-500 focus:ring-teal-500 rounded-md shadow-sm">
                                        <option value="">— select {{ project_term_lower() }} —</option>
                                        @foreach ($projects as $project)
                                            <option value="{{ $project->id }}">{{ $project->name }}</option>
                                        @endforeach
                                    </select>
                                    <x-input-error for="project_id" class="mt-1" />
                                </div>
                            @elseif ($scope_type === 'group')
                                <div>
                                    <x-label for="scope_id" value="Device group" />
                                    <x-searchable-select wire:model="scope_id" placeholder="— select group —"
                                        :options="$groups->map(fn ($g) => ['value' => $g->id, 'label' => $g->name])->values()->all()" />
                                    <x-input-error for="scope_id" class="mt-1" />
                                </div>
                            @else
                                <div>
                                    <x-label for="scope_id" value="Machine" />
                                    <x-searchable-select wire:model="scope_id" placeholder="— select machine —"
                                        :options="$computerChoices->map(fn ($c) => ['value' => $c->id, 'label' => $c->hostname])->values()->all()" />
                                    <x-input-error for="scope_id" class="mt-1" />
                                </div>
                            @endif

                            <div>
                                <x-label for="action" value="Rule" />
                                <select id="action" wire:model.live="action"
                                        class="mt-1 block w-full border-slate-300 focus:border-teal-500 focus:ring-teal-500 rounded-md shadow-sm">
                                    @foreach ($actions as $actionOption)
                                        <option value="{{ $actionOption->value }}">{{ $actionOption->description() }}</option>
                                    @endforeach
                                </select>
                                <x-input-error for="action" class="mt-1" />
                            </div>

                            @if (! in_array($action, ['uninstall', 'block'], true))
                                @if ($policy !== null || count($packageIds) <= 1)
                                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                        <div>
                                            <x-label for="version_mode" value="Version" />
                                            <select id="version_mode" wire:model.live="version_mode"
                                                    class="mt-1 block w-full border-slate-300 focus:border-teal-500 focus:ring-teal-500 rounded-md shadow-sm">
                                                @foreach ($versionModes as $versionModeOption)
                                                    <option value="{{ $versionModeOption->value }}">{{ $versionModeOption->label() }}</option>
                                                @endforeach
                                            </select>
                                            <x-input-error for="version_mode" class="mt-1" />
                                        </div>
                                        @if ($version_mode !== 'latest' || $action === 'force_update')
                                            <div>
                                                <x-label for="desired_version" value="Desired version" />
                                                <x-input id="desired_version" type="text" placeholder="e.g. 24.09"
                                                         class="mt-1 block w-full" wire:model="desired_version" />
                                                <x-input-error for="desired_version" class="mt-1" />
                                            </div>
                                        @endif
                                    </div>
                                    @if ($version_mode === 'exact')
                                        <p class="text-xs text-slate-500 -mt-2">Machines on any other version are moved to exactly this version — including downgrades.</p>
                                    @elseif ($version_mode === 'minimum')
                                        <p class="text-xs text-slate-500 -mt-2">Machines below this version are updated; machines at or above it are left alone.</p>
                                    @elseif ($version_mode === 'maximum')
                                        <p class="text-xs text-slate-500 -mt-2">Freeze: machines above this version are downgraded back to it; updates never go past it.</p>
                                    @endif
                                @else
                                    <p class="text-xs text-slate-500">
                                        Version: <span class="font-medium text-slate-700">Latest</span> — pinning a
                                        specific version is available when exactly one package is selected.
                                    </p>
                                    <x-input-error for="version_mode" class="mt-1" />
                                @endif
                            @endif

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <x-label for="mode" value="Mode" />
                                    <select id="mode" wire:model="mode"
                                            class="mt-1 block w-full border-slate-300 focus:border-teal-500 focus:ring-teal-500 rounded-md shadow-sm">
                                        @foreach ($modes as $modeOption)
                                            <option value="{{ $modeOption->value }}">{{ $modeOption->label() }}</option>
                                        @endforeach
                                    </select>
                                    <x-input-error for="mode" class="mt-1" />
                                </div>
                                <div>
                                    <x-label for="priority" value="Priority" />
                                    <select id="priority" wire:model="priority"
                                            class="mt-1 block w-full border-slate-300 focus:border-teal-500 focus:ring-teal-500 rounded-md shadow-sm">
                                        @foreach ($priorities as $priorityLabel => $priorityValue)
                                            <option value="{{ $priorityValue }}">{{ $priorityLabel }}</option>
                                        @endforeach
                                    </select>
                                    <x-input-error for="priority" class="mt-1" />
                                </div>
                            </div>
                        </div>

                        <div class="pd-card p-6 space-y-4">
                            <h3 class="text-sm font-semibold text-slate-700 uppercase tracking-wider">3 · Scheduling</h3>

                            @if (in_array($action, ['update', 'force_update'], true))
                                <div>
                                    <x-label for="frequency" value="Run frequency" />
                                    <select id="frequency" wire:model="frequency"
                                            class="mt-1 block w-full sm:w-48 border-slate-300 focus:border-teal-500 focus:ring-teal-500 rounded-md shadow-sm">
                                        @foreach ($frequencies as $frequencyOption)
                                            <option value="{{ $frequencyOption->value }}">{{ $frequencyOption->label() }}</option>
                                        @endforeach
                                    </select>
                                    <p class="mt-1 text-xs text-slate-500">How often each machine re-runs this action at most.</p>
                                    <x-input-error for="frequency" class="mt-1" />
                                </div>
                            @endif

                            <div>
                                <x-label value="Maintenance window" />
                                <p class="text-xs text-slate-500 mb-2">Leave all days unticked to run anytime, as soon as drift is detected.</p>
                                <div class="flex flex-wrap gap-3">
                                    @foreach ($weekdays as $dayNumber => $dayLabel)
                                        <label class="flex items-center gap-1.5 text-sm text-slate-700">
                                            <x-checkbox value="{{ $dayNumber }}" wire:model.live="window_days" />
                                            {{ $dayLabel }}
                                        </label>
                                    @endforeach
                                </div>
                                @if ($window_days !== [])
                                    <div class="mt-3 flex items-center gap-3">
                                        <div>
                                            <x-label for="window_start" value="From" class="text-xs" />
                                            <x-input id="window_start" type="time" class="mt-1 block" wire:model="window_start" />
                                        </div>
                                        <div>
                                            <x-label for="window_end" value="Until" class="text-xs" />
                                            <x-input id="window_end" type="time" class="mt-1 block" wire:model="window_end" />
                                        </div>
                                    </div>
                                    <p class="mt-1 text-xs text-slate-500">Overnight windows work too — e.g. 22:00 until 04:00.</p>
                                @endif
                                <x-input-error for="window_start" class="mt-1" />
                                <x-input-error for="window_end" class="mt-1" />
                            </div>

                            {{-- Staged-rollout ring delays exist in the engine but are
                                 hidden here by request: both default to 0 (everywhere at
                                 once). Policies that already carry delays keep them. --}}
                        </div>

                        <div class="rounded-md bg-blue-50 border border-blue-200 p-3 text-sm text-blue-700">
                            <strong>Enforce</strong> queues jobs for machines out of desired state — automatically as agents
                            report in and every 5 minutes while the maintenance window is open. <strong>Audit only</strong>
                            shows compliance but never changes a machine. Version pinning requires a winget package.
                            Changing the desired version restarts the ring rollout.
                        </div>

                        <div class="flex justify-end gap-3">
                            <a href="{{ route('policies.index') }}"
                               class="inline-flex items-center px-4 py-2 bg-white border border-slate-300 rounded-md font-semibold text-xs text-slate-700 uppercase tracking-widest hover:bg-slate-50">
                                Cancel
                            </a>
                            <x-button>
                                {{ $policy ? 'Save changes' : (count($packageIds) > 1 ? 'Create '.count($packageIds).' policies' : 'Create policy') }}
                            </x-button>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>
