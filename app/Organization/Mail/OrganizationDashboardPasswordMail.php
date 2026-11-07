<?php

namespace App\Organization\Mail;

use App\Organization\Models\OrganizationDashboardAccount;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use RuntimeException;

/**
 * First-time setup or password reset link for an Organization Dashboard account.
 */
class OrganizationDashboardPasswordMail extends Mailable
{
    public string $url;

    public function __construct(
        public OrganizationDashboardAccount $account,
        string $token,
        public bool $isSetup,
    ) {
        $baseUrl = config('organization.dashboard.url');

        if (! $baseUrl) {
            throw new RuntimeException('ORGANIZATION_DASHBOARD_URL is not configured.');
        }

        $this->url = rtrim($baseUrl, '/').'/set-password?'.http_build_query([
            'token' => $token,
            'email' => $account->email,
            'type' => $isSetup ? 'setup' : 'reset',
        ]);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->isSetup
                ? 'Set up your Pivot Organization Dashboard password'
                : 'Reset your Pivot Organization Dashboard password',
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.organization-dashboard-password');
    }
}
