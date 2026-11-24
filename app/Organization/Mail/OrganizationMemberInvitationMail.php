<?php

namespace App\Organization\Mail;

use App\Models\User;
use App\Organization\Models\Organization;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Carbon;

/**
 * Invitation link for an existing Pivot user to join an organization.
 */
class OrganizationMemberInvitationMail extends Mailable
{
    public function __construct(
        public User $user,
        public Organization $organization,
        public string $link,
        public Carbon $expiresAt,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "You're invited to join {$this->organization->name} on Pivot");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.organization-member-invitation');
    }
}
