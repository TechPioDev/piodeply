<?php

namespace App\Mail;

use App\Models\PackageRequest;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The one email a package request ever sends: what was decided, and (for a
 * rejection) why. Approval carries a link straight to the new package when
 * one was actually built from the request.
 */
class PackageRequestDecidedMail extends Mailable
{
    public function __construct(public PackageRequest $packageRequest)
    {
    }

    public function envelope(): Envelope
    {
        $approved = $this->packageRequest->status === PackageRequest::STATUS_APPROVED;

        return new Envelope(
            subject: $approved
                ? "\"{$this->packageRequest->name}\" was added to your catalogue"
                : "About your request for \"{$this->packageRequest->name}\""
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.package-request-decided', with: [
            'packageRequest' => $this->packageRequest,
            'packageUrl'     => $this->packageRequest->created_package_id
                ? route('packages.show', $this->packageRequest->created_package_id)
                : null,
        ]);
    }
}
