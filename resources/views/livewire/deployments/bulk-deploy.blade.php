<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-slate-800 leading-tight">{{ __('Bulk deploy') }}</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8 space-y-4">
            @if (session('status'))
                <div class="rounded-md bg-green-50 border border-green-200 p-3 text-sm text-green-700" role="status">
                    {{ session('status') }}
                </div>
            @endif

            <div class="pd-card p-6">
                <p class="text-sm text-slate-500 mb-5">
                    Queue one package across a target — a whole {{ project_term_lower() }}, a device group, or
                    hand-picked machines. Each machine goes through the same checks as a single deploy — machines
                    already up to date are skipped, and anything already queued is not duplicated. For ongoing
                    desired state, use a <a href="{{ route('policies.index') }}" class="pd-link">{{ policy_term_lower() }}</a> instead.
                </p>

                <form wire:submit="queue" class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Target type</label>
                        <div class="flex flex-wrap gap-4">
                            @foreach (['site' => project_term(), 'group' => 'Device group', 'machines' => 'Specific machines'] as $value => $label)
                                <label class="inline-flex items-center gap-1.5 text-sm text-slate-600 select-none">
                                    <input type="radio" wire:model.live="targetType" value="{{ $value }}"
                                           class="border-slate-300 text-teal-600 focus:ring-teal-500">
                                    {{ $label }}
                                </label>
                            @endforeach
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        @if ($targetType === 'site')
                            <div>
                                <label class="block text-sm font-medium text-slate-700 mb-1">{{ project_term() }}</label>
                                <select wire:model.live="projectId"
                                        class="block w-full border-slate-300 focus:border-teal-500 focus:ring-teal-500 rounded-md shadow-sm text-sm">
                                    <option value="">— select {{ project_term_lower() }} —</option>
                                    @foreach ($projects as $project)
                                        <option value="{{ $project->id }}">{{ $project->name }}</option>
                                    @endforeach
                                </select>
                                <x-input-error for="projectId" class="mt-1" />
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-slate-700 mb-1">Ring</label>
                                <select wire:model.live="ring"
                                        class="block w-full border-slate-300 focus:border-teal-500 focus:ring-teal-500 rounded-md shadow-sm text-sm">
                                    <option value="">All rings</option>
                                    @foreach ($rings as $r)
                                        <option value="{{ $r->value }}">{{ $r->label() }}</option>
                                    @endforeach
                                </select>
                            </div>
                        @elseif ($targetType === 'group')
                            <div>
                                <label class="block text-sm font-medium text-slate-700 mb-1">Device group</label>
                                <x-searchable-select wire:model.live="groupId" placeholder="— select group —"
                                    :options="$groups->map(fn ($g) => ['value' => $g->id, 'label' => $g->name])->values()->all()" />
                                <x-input-error for="groupId" class="mt-1" />
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-slate-700 mb-1">Ring</label>
                                <select wire:model.live="ring"
                                        class="block w-full border-slate-300 focus:border-teal-500 focus:ring-teal-500 rounded-md shadow-sm text-sm">
                                    <option value="">All rings</option>
                                    @foreach ($rings as $r)
                                        <option value="{{ $r->value }}">{{ $r->label() }}</option>
                                    @endforeach
                                </select>
                            </div>
                        @else
                            <div class="md:col-span-2 space-y-2">
                                <label class="block text-sm font-medium text-slate-700">Machines</label>

                                @if ($selectedMachines->isNotEmpty())
                                    <div class="flex flex-wrap gap-2">
                                        @foreach ($selectedMachines as $machine)
                                            <span class="inline-flex items-center gap-1.5 rounded-full bg-teal-50 border border-teal-200 text-teal-800 text-sm pl-3 pr-1.5 py-1">
                                                {{ $machine->hostname }}
                                                <button type="button" wire:click="removeMachine({{ $machine->id }})"
                                                        class="h-5 w-5 grid place-content-center rounded-full text-teal-600 hover:bg-teal-100 hover:text-rose-600 font-bold"
                                                        title="Remove {{ $machine->hostname }}">−</button>
                                            </span>
                                        @endforeach
                                        <span class="self-center text-xs text-slate-400">{{ $selectedMachines->count() }} selected</span>
                                    </div>
                                @endif

                                <input type="search" wire:model.live.debounce.250ms="machineSearch"
                                       placeholder="Search machines to add…"
                                       class="block w-full border-slate-300 focus:border-teal-500 focus:ring-teal-500 rounded-md shadow-sm text-sm">
                                <div class="max-h-56 overflow-y-auto divide-y divide-slate-100 border border-slate-200 rounded-md">
                                    @forelse ($machineChoices as $choice)
                                        <div class="flex items-center justify-between px-4 py-2 hover:bg-slate-50">
                                            <span class="text-sm text-slate-700">{{ $choice->hostname }}</span>
                                            <button type="button" wire:click="addMachine({{ $choice->id }})"
                                                    class="h-7 w-7 grid place-content-center rounded-full bg-teal-600 text-white text-base font-bold hover:bg-teal-700"
                                                    title="Add {{ $choice->hostname }}">+</button>
                                        </div>
                                    @empty
                                        <p class="px-4 py-6 text-sm text-slate-500 text-center">
                                            {{ trim($machineSearch) !== '' ? 'Nothing matches that search.' : 'Everything is already selected.' }}
                                        </p>
                                    @endforelse
                                </div>
                                <x-input-error for="machineIds" class="mt-1" />
                            </div>
                        @endif

                        <div class="md:col-span-2">
                            <label class="block text-sm font-medium text-slate-700 mb-1">Package</label>
                            <x-searchable-select wire:model.live="packageId" placeholder="— select package —"
                                :options="$packages->map(fn ($p) => ['value' => $p->id, 'label' => $p->name . ' (' . $p->installer_type->label() . ')'])->values()->all()" />
                            <x-input-error for="packageId" class="mt-1" />
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1">Action</label>
                            <select wire:model.live="action"
                                    class="block w-full border-slate-300 focus:border-teal-500 focus:ring-teal-500 rounded-md shadow-sm text-sm">
                                @foreach ($actions as $a)
                                    <option value="{{ $a->value }}">{{ $a->label() }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1">Priority</label>
                            <select wire:model="priority"
                                    class="block w-full border-slate-300 focus:border-teal-500 focus:ring-teal-500 rounded-md shadow-sm text-sm" title="1 = highest">
                                @foreach (range(1, 10) as $p)
                                    <option value="{{ $p }}">P{{ $p }}</option>
                                @endforeach
                            </select>
                        </div>

                        @if ($versionKnown)
                            <div>
                                <label class="block text-sm font-medium text-slate-700 mb-1">Pin version <span class="text-slate-400 font-normal">(optional)</span></label>
                                <input type="text" wire:model.live.debounce.400ms="targetVersion" placeholder="latest"
                                       class="block w-full font-mono text-sm border-slate-300 focus:border-teal-500 focus:ring-teal-500 rounded-md shadow-sm">
                                <x-input-error for="targetVersion" class="mt-1" />
                            </div>
                        @endif
                    </div>

                    <label class="flex items-center gap-2 text-sm text-slate-600 select-none">
                        <input type="checkbox" wire:model.live="force" class="rounded border-slate-300 text-teal-600 focus:ring-teal-500">
                        Force even where already satisfied (repairs broken installs)
                    </label>

                    @php
                        $hasTarget = match ($targetType) {
                            'site' => (bool) $projectId,
                            'group' => (bool) $groupId,
                            'machines' => $machineIds !== [],
                            default => false,
                        };
                        $canSubmit = $hasTarget && $packageId && $targetCount > 0;
                    @endphp
                    <div class="flex items-center justify-between pt-2 border-t border-slate-100">
                        <p class="text-sm text-slate-500">
                            @if ($hasTarget)
                                Targets <b class="text-slate-800">{{ $targetCount }}</b> {{ Str::plural('machine', $targetCount) }}{{ $ring !== '' ? ' in the '.$ring.' ring' : '' }}.
                            @else
                                Select a target to see how many machines will be reached.
                            @endif
                        </p>
                        <x-button type="submit" :disabled="! $canSubmit"
                                  class="whitespace-nowrap {{ ! $canSubmit ? '!bg-slate-300 !text-slate-600 cursor-not-allowed hover:!bg-slate-300' : '' }}">
                            Queue deployment
                        </x-button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
