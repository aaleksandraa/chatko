<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CheckoutOrderCustomerMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(public readonly array $data)
    {
    }

    public function envelope(): Envelope
    {
        $orderId = (string) ($this->data['order_id'] ?? '');

        return new Envelope(
            subject: $orderId !== '' ? 'Potvrda narudzbe #'.$orderId : 'Potvrda narudzbe',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.checkout-order-customer',
        );
    }
}
