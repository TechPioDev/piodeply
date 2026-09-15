@props(['items'])

{{--
    Each item is ['label' => string, 'url' => ?string]. A null url renders as
    plain text — used for a parent the current user cannot navigate to (a
    client-bound user has no "Clients" list to link back to), so the trail
    still orients them without offering a link that would 403.
--}}
<nav aria-label="Breadcrumb" class="flex items-center gap-1.5 text-xs text-slate-400 mb-1 flex-wrap">
    @foreach ($items as $item)
        @if (! $loop->first)
            <span aria-hidden="true">/</span>
        @endif
        @if ($loop->last)
            <span class="text-slate-500 font-medium" aria-current="page">{{ $item['label'] }}</span>
        @elseif ($item['url'] ?? null)
            <a href="{{ $item['url'] }}" class="hover:text-teal-700 hover:underline">{{ $item['label'] }}</a>
        @else
            <span>{{ $item['label'] }}</span>
        @endif
    @endforeach
</nav>
