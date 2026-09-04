<?php

declare(strict_types=1);

namespace App\Http\Controllers\Widget;

use App\Domain\Auth\Widget\WidgetVisitor;
use App\Domain\Webchat\Health\WidgetHealth;
use App\Domain\Webchat\Identity\SignedIdentityLinker;
use App\Domain\Webchat\Identity\SignedIdentityVerifier;
use App\Domain\Webchat\Visitor\VisitorConversation;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Doc 13 FR-07 `POST /api/widget/v1/identify` — the host page's signed
 * identity for THIS visitor. Verified (SEC-03) → linked under A-61; refused →
 * a 204 and a Health counter, NEVER an error to the visitor ("invalid
 * signatures are ignored"): the visitor cannot fix the operator's signing.
 * The response names what the widget may show for continuity (the asserted
 * display name — BR-05: their own asserted name, never "verified") and
 * whether the switch rule fired (EC-04 → the widget re-sessions).
 */
final class WidgetIdentifyController
{
    public function __construct(
        private readonly VisitorConversation $conversations,
        private readonly SignedIdentityVerifier $verifier,
        private readonly SignedIdentityLinker $linker,
        private readonly WidgetHealth $health,
    ) {}

    public function store(Request $request): SymfonyResponse
    {
        $visitor = $request->user('widget');
        if (! $visitor instanceof WidgetVisitor) {
            throw new NotFoundHttpException;
        }
        $widget = $this->conversations->widgetFor($request);

        /** @var array{payload: array<string, mixed>, sig: string} $input */
        $input = $request->validate([
            'payload' => ['required', 'array'],
            'payload.user_ref' => ['required', 'string', 'max:128'],
            'payload.name' => ['nullable', 'string', 'max:120'],
            'payload.phone' => ['nullable', 'string', 'max:32'],
            'payload.email' => ['nullable', 'string', 'max:254'],
            'payload.ts' => ['required'],
            'sig' => ['required', 'string', 'max:128'],
        ]);

        $refusal = $this->verifier->refusal($widget, $input['payload'], strtolower($input['sig']), CarbonImmutable::now('UTC'));
        if ($refusal !== '') {
            Log::info('widget signed identity refused', ['reason' => $refusal, 'security_metric' => 'widget.identity_refused']);
            $this->health->beacon($widget, 'invalid_signature', $refusal, null, null, null);

            return new Response('', 204);
        }

        $outcome = $this->linker->assert($widget, $visitor, $input['payload']);

        return new JsonResponse([
            'asserted' => true,
            'display' => $outcome['display'],
            'switched' => $outcome['switched'],
            're_session' => $outcome['switched'],
        ]);
    }
}
