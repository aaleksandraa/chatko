<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TenantUsageLimitAlertMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(public readonly array $payload)
    {
    }

    public function envelope(): Envelope
    {
        $tenantName = (string) ($this->payload['tenant_name'] ?? 'Tenant');

        return new Envelope(
            subject: sprintf('[Chatko] Usage limit reached - %s', $tenantName),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.tenant-usage-limit-alert',
        );
    }
}

