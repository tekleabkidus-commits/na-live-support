<?php

declare(strict_types=1);

namespace App\Domain\Sms\Templates;

use RuntimeException;

/**
 * Doc 14 FR-05 / SEC-05 / AC-10 — a template door refused the operator's input
 * with a STABLE refusal code (C-04's shape, rendered as a 422 problem with the
 * code under `errors`): `variable_not_allowed` (a variable the event does not
 * provide — FR-05 "cannot publish"), `arbitrary_url` (SEC-05 — smishing-shape
 * prevention: a literal URL in a template fails publish), `link_domain_unset`
 * ({{link}} used while `sms.link.domain` is empty), `unknown_event`,
 * `body_too_long`, `template_not_found`, `version_not_found`,
 * `duplicate_template` (one template per event per language), `fixture_phone_required`
 * (STOP-M: a non-production test-send only ever targets +251-90000xxxx).
 */
final class TemplateRefused extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $extra
     */
    public function __construct(public readonly string $refusalCode, string $message, public readonly array $extra = [])
    {
        parent::__construct($message);
    }
}
