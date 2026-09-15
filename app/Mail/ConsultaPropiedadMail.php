<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ConsultaPropiedadMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<string, mixed>  $consulta
     * @param  array<string, mixed>  $propiedad
     */
    public function __construct(
        public readonly array $consulta,
        public readonly array $propiedad,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            replyTo: [
                new Address($this->consulta['email'], $this->consulta['nombre']),
            ],
            subject: 'Nueva consulta por la propiedad '.$this->propiedad['referencia'],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.consulta-propiedad',
            text: 'emails.consulta-propiedad-texto',
        );
    }

    /**
     * @return array<int, mixed>
     */
    public function attachments(): array
    {
        return [];
    }
}
