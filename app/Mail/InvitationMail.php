<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The FR-17 invitation email (Doc 02 FR-17, EC-04, FS-02, §14; ADR 0007).
 *
 * Same queue family, same retry posture and same considered C-13 tension as
 * PasswordResetMail — see that class; the reasoning is identical and lives in
 * one place. The differences are the purpose (first password, not a reset),
 * the validity horizon (FR-17's 7 days, not minutes) and the copy.
 */
final class InvitationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 6;

    public function __construct(
        public readonly string $inviteUrl,
        public readonly string $workspaceName,
        public readonly int $validDays,
    ) {
        $this->onQueue('mail');
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300, 900, 3600, 10800];
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: (string) __('auth.mail.invite_subject', ['workspace' => $this->workspaceName]),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.auth.invitation',
            with: [
                'inviteUrl' => $this->inviteUrl,
                'workspaceName' => $this->workspaceName,
                'validDays' => $this->validDays,
            ],
        );
    }
}
