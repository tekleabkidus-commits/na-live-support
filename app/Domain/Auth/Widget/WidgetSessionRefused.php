<?php

declare(strict_types=1);

namespace App\Domain\Auth\Widget;

use App\Support\Problem\ErrorCode;
use App\Support\Problem\ProblemDetails;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * A widget session request was refused at the server layer (Doc 02 FR-21,
 * AC-06, EC-08).
 *
 * ONE code, one shape, for every allowlist refusal — bad origin, bad ancestor,
 * missing origin, unconfigured widget. AC-06 calls this "the server layer":
 * it fires with or without a browser in the loop, which is what makes it
 * independent of the CORS layer (a curl request never sees CORS; it sees
 * this). The constructor logs the C-10-style security metric so a stream of
 * blocked origins is visible to monitoring — and, later, to Doc 13's Health
 * tab, which counts exactly these.
 */
final class WidgetSessionRefused extends RuntimeException
{
    private function __construct(public readonly string $internalReason, ?string $blockedOrigin)
    {
        parent::__construct(sprintf('Widget session refused: %s', $internalReason));

        // The ORIGIN is logged — it is the attacker-visible fact Doc 13's
        // blocked-origin Health counter needs. No credential appears here:
        // the widget key never reaches this class (SEC-08).
        Log::warning('widget session refused', [
            'reason' => $internalReason,
            'origin' => $blockedOrigin,
            'security_metric' => 'widget.origin_blocked',
        ]);
    }

    public static function originNotAllowed(?string $origin): self
    {
        return new self('origin_not_allowed', $origin);
    }

    public static function ancestorNotAllowed(?string $ancestor): self
    {
        return new self('ancestor_not_allowed', $ancestor);
    }

    public static function originMissing(): self
    {
        return new self('origin_missing', null);
    }

    public static function widgetUnconfigured(): self
    {
        return new self('widget_unconfigured', null);
    }

    public function render(Request $request): Response
    {
        // One render path for all four reasons — byte-identical refusals, so
        // the response never narrates which layer said no (the AC-01 lesson).
        return ProblemDetails::response(
            code: ErrorCode::OriginNotAllowed,
            status: 403,
            detail: (string) __('auth.widget.origin_not_allowed'),
        );
    }
}
