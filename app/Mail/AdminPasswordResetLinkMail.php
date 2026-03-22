<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AdminPasswordResetLinkMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $recipientName,
        public readonly string $tenantName,
        public readonly string $resetUrl,
        public readonly int $expiresMinutes = 60,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Reset your Chatko password',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.admin-password-reset-link',
        );
    }
}

