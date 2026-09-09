<?php

declare(strict_types=1);

namespace App\Domain\Sms\Gateways;

/**
 * What a driver sends (T-02): the platform's own message id (the correlation
 * key — vendor notes §4: the vendor's synchronous id is UNKNOWN), the E.164
 * destination, the RENDERED text, the sender id for this route, and the DLR
 * callback URL the gateway is told per send (P-DLR-AUTH: the URL is the
 * credential — it is never logged).
 */
final readonly class RenderedMessage
{
    public function __construct(
        public string $messageId,
        public string $phoneE164,
        public string $text,
        public ?string $senderId,
        public ?string $callbackUrl,
    ) {}
}
