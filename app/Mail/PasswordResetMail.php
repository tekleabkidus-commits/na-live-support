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
 * The FR-16 reset email (Doc 02 FR-16, FS-02, §14; ADR 0007).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * QUEUED ON THE `mail` FAMILY, RETRY ×6. FS-02: provider down → the message
 * waits on redis-durable and retries on a widening schedule (≈9 h of patience)
 * rather than failing the HTTP request that asked for it. The queue name is
 * ADR 0007's; the tries/backoff live HERE and not on the supervisor because
 * they are THIS document's requirement, not a property of all future mail.
 *
 * A CONSIDERED C-13 TENSION, recorded rather than hidden: the reset URL —
 * which embeds the single-use token — serializes into the queued job payload,
 * because that is what "queued email containing a link" means. The exposure is
 * bounded on every axis: the token is single-use, dies at auth.password.reset_ttl
 * (default 60 min), is invalidated by any resend, and the payload lives on
 * redis-durable inside the same trust boundary as the session store. The
 * alternative — storing plaintext tokens so a send-time job could look them up
 * — would violate SEC-02 outright to satisfy C-13's letter.
 *
 * LOCALE IS RESOLVED AT DISPATCH, not at render: the worker that renders this
 * has no tenant bound, so the C-08 chain's tenant hop cannot run there. The
 * caller resolves user → tenant default → en while the tenant IS bound and
 * pins the result with ->locale() (§14's per-language templating).
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class PasswordResetMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 6;

    public function __construct(
        public readonly string $resetUrl,
        public readonly string $workspaceName,
        public readonly int $validMinutes,
    ) {
        $this->onQueue('mail');
    }

    /**
     * FS-02's "retry ×6 backoff": 1 m, 5 m, 15 m, 1 h, 3 h — six attempts in
     * all, spanning roughly the length of a provider incident worth waiting
     * out. After the last, the job fails visibly into Horizon's failed set.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300, 900, 3600, 10800];
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: (string) __('auth.mail.reset_subject', ['workspace' => $this->workspaceName]),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.auth.reset',
            with: [
                'resetUrl' => $this->resetUrl,
                'workspaceName' => $this->workspaceName,
                'validMinutes' => $this->validMinutes,
            ],
        );
    }
}
