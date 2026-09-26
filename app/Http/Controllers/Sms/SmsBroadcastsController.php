<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sms;

use App\Domain\Sms\Broadcasts\AudienceQuery;
use App\Domain\Sms\Broadcasts\BroadcastWriter;
use App\Domain\Sms\Templates\TemplateRefused;
use App\Http\Controllers\Controller;
use App\Models\SmsBroadcast;
use App\Models\User;
use App\Support\Problem\ErrorCode;
use App\Support\Problem\ProblemDetails;
use App\Support\Rbac\CapabilityMapProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * FR-17/FR-18's doors (§8): draft/edit/schedule ride `sms.send` +
 * `sms.templates.view`; the LAUNCH door alone is A-64's HIGH pair
 * (`sms.send` AND `sms.gateways.manage`, both on the route) with the typed
 * confirm at ≥ 1,000; preview answers the SAME query the chunker runs
 * (AC-06) plus EC-06's pin notice. No text field exists anywhere here — the
 * broadcast is a template pin plus filters (BR-01).
 */
final class SmsBroadcastsController extends Controller
{
    public function __construct(
        private readonly BroadcastWriter $writer,
        private readonly AudienceQuery $audience,
        private readonly CapabilityMapProvider $verdicts,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $rows = [];
        foreach (SmsBroadcast::query()->orderByDesc('id')->limit(50)->get() as $broadcast) {
            $rows[] = $this->summary($broadcast);
        }

        return new JsonResponse(['broadcasts' => $rows]);
    }

    public function show(Request $request, string $broadcastId): JsonResponse
    {
        return new JsonResponse($this->summary($this->broadcast($broadcastId)));
    }

    public function store(Request $request): JsonResponse
    {
        if (($deny = $this->requireSecondary($request, 'sms.templates.view')) !== null) {
            return $deny;
        }
        $input = $this->input($request, [
            'name' => ['required', 'string', 'min:3', 'max:120'],
            'template_id' => ['required', 'uuid'],
            'audience_filter' => ['sometimes', 'array'],
        ]);
        if ($input instanceof JsonResponse) {
            return $input;
        }
        /** @var array{name: string, template_id: string, audience_filter?: array<string, mixed>} $input */
        try {
            $broadcast = $this->writer->create($input['name'], $input['template_id'], $input['audience_filter'] ?? [], $this->user($request));
        } catch (TemplateRefused $e) {
            return $this->refused($e);
        }

        return new JsonResponse($this->summary($broadcast), 201);
    }

    public function update(Request $request, string $broadcastId): JsonResponse
    {
        $broadcast = $this->broadcast($broadcastId);
        $input = $this->input($request, [
            'name' => ['sometimes', 'string', 'min:3', 'max:120'],
            'audience_filter' => ['sometimes', 'array'],
            'scheduled_at' => ['sometimes', 'nullable', 'date'],
            'repin' => ['sometimes', 'boolean'],
        ]);
        if ($input instanceof JsonResponse) {
            return $input;
        }
        /** @var array<string, mixed> $input */
        try {
            return new JsonResponse($this->summary($this->writer->update($broadcast, $input, $this->user($request))));
        } catch (TemplateRefused $e) {
            return $this->refused($e);
        }
    }

    /** AC-06's preview: the SAME query the chunker executes, counted now, plus EC-06's pin notice. */
    public function preview(Request $request, string $broadcastId): JsonResponse
    {
        $broadcast = $this->broadcast($broadcastId);

        return new JsonResponse([
            'audience_count' => $this->audience->count($this->writer->filters($broadcast), $this->user($request)),
            'typed_confirmation_required' => $this->audience->count($this->writer->filters($broadcast), $this->user($request)) >= BroadcastWriter::TYPED_CONFIRM_THRESHOLD,
            'pinned_version_id' => $broadcast->template_version_id,
            'template_live_version_id_if_newer' => $this->writer->pinNotice($broadcast),
        ]);
    }

    /** A-64: the route carries sms.gateways.manage (the HIGH key); the controller adds the sms.send half. */
    public function launch(Request $request, string $broadcastId): JsonResponse
    {
        if (($deny = $this->requireSecondary($request, 'sms.send')) !== null) {
            return $deny;
        }
        $broadcast = $this->broadcast($broadcastId);
        $input = $this->input($request, ['typed_confirmation' => ['sometimes', 'nullable', 'string', 'max:120']]);
        if ($input instanceof JsonResponse) {
            return $input;
        }
        /** @var array{typed_confirmation?: ?string} $input */
        try {
            return new JsonResponse($this->summary($this->writer->launch($broadcast, $this->user($request), $input['typed_confirmation'] ?? null)));
        } catch (TemplateRefused $e) {
            return $this->refused($e);
        }
    }

    public function cancel(Request $request, string $broadcastId): JsonResponse
    {
        try {
            return new JsonResponse($this->summary($this->writer->cancel($this->broadcast($broadcastId), $this->user($request))));
        } catch (TemplateRefused $e) {
            return $this->refused($e);
        }
    }

    /** The second key of a compound gate (the route declares the first); 403 in the house shape. */
    private function requireSecondary(Request $request, string $permission): ?JsonResponse
    {
        $user = $request->user();
        if ($user instanceof User && $this->verdicts->mapFor($user)->allows($permission)) {
            return null;
        }

        return ProblemDetails::response(ErrorCode::Forbidden, 403, 'forbidden', errors: ['permission' => [$permission]]);
    }

    /** @return array<string, mixed> */
    private function summary(SmsBroadcast $broadcast): array
    {
        return [
            'id' => $broadcast->identifier(),
            'name' => $broadcast->name,
            'state' => $broadcast->state,
            'template_id' => $broadcast->template_id,
            'pinned_version_id' => $broadcast->template_version_id,
            'audience_filter' => $broadcast->audience_filter,
            'audience_count_preview' => $broadcast->audience_count_preview,
            'scheduled_at' => $broadcast->scheduled_at?->toIso8601String(),
            'total' => (int) $broadcast->total,
            'sent' => (int) $broadcast->sent,
            'skipped' => (int) $broadcast->skipped,
            'failed' => (int) $broadcast->failed,
            'cost_accrued' => (string) $broadcast->cost_accrued,
            'launched_at' => $broadcast->launched_at?->toIso8601String(),
            'completed_at' => $broadcast->completed_at?->toIso8601String(),
            'canceled_at' => $broadcast->canceled_at?->toIso8601String(),
        ];
    }

    private function broadcast(string $broadcastId): SmsBroadcast
    {
        $broadcast = SmsBroadcast::query()->find($broadcastId);
        if ($broadcast === null) {
            throw new NotFoundHttpException; // C-10
        }

        return $broadcast;
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    /**
     * @param  array<string, array<int, string>>  $rules
     * @return array<string, mixed>|JsonResponse
     */
    private function input(Request $request, array $rules): array|JsonResponse
    {
        try {
            /** @var array<string, mixed> $validated */
            $validated = $request->validate($rules);

            return $validated;
        } catch (ValidationException $e) {
            /** @var array<string, list<string>> $errors */
            $errors = $e->errors();

            return ProblemDetails::response(ErrorCode::ValidationFailed, 422, 'invalid input', errors: $errors);
        }
    }

    private function refused(TemplateRefused $e): JsonResponse
    {
        return ProblemDetails::response(
            code: ErrorCode::ValidationFailed,
            status: 422,
            detail: (string) __('sms.refused.'.$e->refusalCode),
            errors: ['refusal' => [$e->refusalCode]] + array_map(
                static fn (mixed $v): array => [is_scalar($v) ? (string) $v : json_encode($v, JSON_THROW_ON_ERROR)],
                $e->extra,
            ),
        );
    }
}
