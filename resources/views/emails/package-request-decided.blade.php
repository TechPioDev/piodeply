@component('mail::message')
@if ($packageRequest->status === \App\Models\PackageRequest::STATUS_APPROVED)
# Good news, {{ $packageRequest->requester->name }}

**{{ $packageRequest->name }}** has been added to your catalogue.

@if ($packageUrl)
@component('mail::button', ['url' => $packageUrl])
View the package
@endcomponent
@endif

It's ready to deploy from Packages whenever you need it.
@else
# About your request for {{ $packageRequest->name }}

We looked into adding **{{ $packageRequest->name }}** to your catalogue, but we're not able to add it right now.

@if ($packageRequest->decision_note)
**Reason:** {{ $packageRequest->decision_note }}
@endif

If you'd still like this software, or have questions, just reply to this email — a real person reads it.
@endif

Thanks,<br>
The PioDeploy team
@endcomponent
