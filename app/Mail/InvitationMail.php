<?php

namespace App\Mail;

use App\Models\Invitation;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class InvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Invitation $invitation) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('You\'re invited to join :app', ['app' => config('app.name')]),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.invitations.invitation',
            with: [
                'inviteUrl' => url(route('register', ['code' => $this->invitation->code], false)),
                'role' => str($this->invitation->role->value)->headline()->toString(),
                'expiresAt' => $this->invitation->expires_at?->toFormattedDateString() ?? __('never'),
            ],
        );
    }
}
