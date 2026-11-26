<?php

namespace App\Organization\Mail;

use App\Organization\Models\Organization;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Carbon;

/**
 * Individual invitation to join an organization: the join link and the 6-character code.
 */
class OrganizationInvitationMail extends Mailable
{
    public function __construct(
        public Organization $organization,
        public string $link,
        public string $code,
        public Carbon $expiresAt,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "You're invited to join {$this->organization->name} on Pivot");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.organization-invitation');
    }
}
