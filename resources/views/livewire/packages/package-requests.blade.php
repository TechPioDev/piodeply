<div>
    <x-slot name="header">
        <div>
            <h2 class="font-semibold text-xl text-slate-900 leading-tight">{{ __('Package requests') }}</h2>
            <p class="text-sm text-slate-500 mt-0.5">
                @if ($isTenant)
                    Ask us to add software to your catalogue — we only ship tested packages, so every request is reviewed first.
                @else
                    What clients have asked for, waiting on a decision.
                @endif
            </p>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-5">
            @if (session('status'))
                <div class="pd-card p-3 text-sm text-emerald-700 !bg-emerald-50 border-emerald-200" role="status">{{ session('status') }}</div>
            @endif
            @if (session('error'))
                <div class="pd-card p-3 text-sm text-red-700 !bg-red-50 border-red-200" role="alert">{{ session('error') }}</div>
            @endif

            @if ($isTenant)
                <div class="pd-card p-6">
                    <h3 class="text-sm font-semibold text-slate-700 uppercase tracking-wide mb-3">Request a package</h3>
                    <form wire:submit="submit" class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div class="sm:col-span-2">
                            <label class="block text-sm font-medium text-slate-700 mb-1">Software name</label>
                            <input type="text" wire:model="name" placeholder="e.g. Zoom, Adobe Acrobat Reader"
                                   class="w-full border-slate-300 rounded-md shadow-sm">
                            @error('name') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1">Vendor <span class="text-slate-400 font-normal">(optional)</span></label>
                            <input type="text" wire:model="vendor" class="w-full border-slate-300 rounded-md shadow-sm">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1">Link to the software <span class="text-slate-400 font-normal">(optional)</span></label>
                            <input type="text" wire:model="homepage" placeholder="https://..." class="w-full border-slate-300 rounded-md shadow-sm">
                            @error('homepage') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div class="sm:col-span-2">
                            <label class="block text-sm font-medium text-slate-700 mb-1">Why do you need it? <span class="text-slate-400 font-normal">(optional)</span></label>
                            <textarea wire:model="notes" rows="2" class="w-full border-slate-300 rounded-md shadow-sm"></textarea>
                            @error('notes') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div class="sm:col-span-2">
                            <button type="submit"
                                    class="inline-flex items-center gap-2 px-4 py-2 bg-teal-700 rounded-lg font-semibold text-sm text-white shadow-sm hover:bg-teal-800 transition">
                                Send request
                            </button>
                        </div>
                    </form>
                </div>
            @endif

            <div class="pd-card p-6">
                <div class="flex items-center justify-between mb-3">
                    <h3 class="text-sm font-semibold text-slate-700 uppercase tracking-wide">
                        {{ $isTenant ? 'Your requests' : 'All requests' }}
                        @if (! $isTenant && $openCount > 0)
                            <span class="ml-1 pd-badge pd-badge-amber">{{ $openCount }} pending</span>
                        @endif
                    </h3>
                    @unless ($isTenant)
                        <label class="flex items-center gap-2 text-sm text-slate-600">
                            <input type="checkbox" wire:model.live="openOnly" class="rounded border-slate-300">
                            Pending only
                        </label>
                    @endunless
                </div>

                @if ($requests->isEmpty())
                    <p class="text-sm text-slate-400 py-6 text-center">
                        {{ $isTenant ? "You haven't requested anything yet." : 'Nothing pending.' }}
                    </p>
                @else
                    <div class="overflow-x-auto -mx-6"><table class="min-w-full divide-y divide-slate-100">
                        <thead class="bg-slate-50">
                            <tr>
                                <th class="pd-th">Package</th>
                                @unless ($isTenant)<th class="pd-th">Client</th>@endunless
                                <th class="pd-th">Requested by</th>
                                <th class="pd-th">When</th>
                                <th class="pd-th">Status</th>
                                @unless ($isTenant)<th class="pd-th">Actions</th>@endunless
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($requests as $request)
                                <tr>
                                    <td class="px-6 py-3">
                                        <p class="text-sm font-medium text-slate-800">{{ $request->name }}</p>
                                        @if ($request->vendor)
                                            <p class="text-xs text-slate-400">{{ $request->vendor }}</p>
                                        @endif
                                        @if ($request->notes)
                                            <p class="text-xs text-slate-400 mt-1 italic">"{{ Str::limit($request->notes, 80) }}"</p>
                                        @endif
                                    </td>
                                    @unless ($isTenant)
                                        <td class="px-6 py-3 text-sm text-slate-600">{{ $request->client->company_name }}</td>
                                    @endunless
                                    <td class="px-6 py-3 text-sm text-slate-600">{{ $request->requester->name }}</td>
                                    <td class="px-6 py-3 whitespace-nowrap text-xs text-slate-400" title="{{ $request->created_at }}">
                                        {{ $request->created_at->diffForHumans(short: true) }}
                                    </td>
                                    <td class="px-6 py-3">
                                        @if ($request->status === 'pending')
                                            <span class="pd-badge pd-badge-amber"><span class="pd-dot"></span>Pending</span>
                                        @elseif ($request->status === 'approved')
                                            <span class="pd-badge pd-badge-green"><span class="pd-dot"></span>Added</span>
                                        @else
                                            <span class="pd-badge pd-badge-slate"><span class="pd-dot"></span>Not added</span>
                                        @endif
                                        @if ($request->status !== 'pending' && $request->decision_note)
                                            <p class="text-xs text-slate-400 mt-1">{{ $request->decision_note }}</p>
                                        @endif
                                        @if ($request->status === 'approved' && $request->createdPackage)
                                            <a href="{{ route('packages.show', $request->createdPackage) }}" class="block text-xs text-teal-600 hover:underline mt-1">View package →</a>
                                        @endif
                                    </td>
                                    @unless ($isTenant)
                                        <td class="px-6 py-3 whitespace-nowrap">
                                            @if ($request->status === 'pending')
                                                <div class="flex items-center gap-3">
                                                    <a href="{{ route('packages.create', ['fulfillsRequestId' => $request->id]) }}"
                                                       class="text-xs font-semibold text-teal-700 hover:underline">Build this package</a>
                                                    <button type="button" wire:click="startReject({{ $request->id }})"
                                                            class="text-xs font-semibold text-red-600 hover:underline">Reject</button>
                                                </div>
                                            @endif
                                        </td>
                                    @endunless
                                </tr>
                                @if (! $isTenant && $rejectingId === $request->id)
                                    <tr>
                                        <td colspan="6" class="px-6 py-3 bg-red-50">
                                            <div class="flex items-center gap-2">
                                                <input type="text" wire:model="rejectionReason"
                                                       placeholder="Reason (optional) — sent to the requester"
                                                       class="flex-1 border-slate-300 rounded-md shadow-sm text-sm">
                                                <button type="button" wire:click="confirmReject"
                                                        class="px-3 py-1.5 bg-red-600 text-white text-xs font-semibold rounded-md hover:bg-red-700">Confirm reject</button>
                                                <button type="button" wire:click="cancelReject"
                                                        class="px-3 py-1.5 text-xs text-slate-500 hover:text-slate-700">Cancel</button>
                                            </div>
                                        </td>
                                    </tr>
                                @endif
                            @endforeach
                        </tbody>
                    </table></div>
                    {{ $requests->links() }}
                @endif
            </div>
        </div>
    </div>
</div>
