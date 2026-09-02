<?php

declare(strict_types=1);

namespace App\Domain\Automation\Webhooks;

use DomainException;

/** SEC-02: the SSRF rules refused a destination — the stable code is SsrfRules::CODE (C-04). */
final class WebhookDestinationRefused extends DomainException {}
