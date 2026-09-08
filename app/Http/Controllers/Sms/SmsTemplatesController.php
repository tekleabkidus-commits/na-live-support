<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sms;

use App\Domain\Sms\Templates\TemplatePublisher;
use App\Domain\Sms\Templates\TemplateReader;
use App\Domain\Sms\Templates\TemplateRefused;
use App\Domain\Sms\Templates\TemplateWriter;
use App\Domain\Sms\Templates\TestSend;
use App\Http\Controllers\Controller;
use App\Models\SmsTemplate;
use App\Models\SmsTemplateVersion;
use App\Models\User;
use App\Support\Problem\ErrorCode;
use App\Support\Problem\ProblemDetails;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Doc 14 FR-04 / FR-05 / §8 — the templates' JSON doors (the Designer shape).
 * Reads under `sms.templates.view`; create under `.create`; publish, rollback,
 * update, validate and test-send under `.edit`; delete under `.delete`; every
 * mutation carries its registered audit key.
 *
 * BR-01 BY CONSTRUCTION: template CONTENT travels as `template_body` on these
 * doors — the operator's template (FR-04's design), never a per-send text —
 * and the test-send door takes a template VERSION and a number, nothing to
 * say. `SmsNoFreeTextTest` sweeps this controller: no parameter or rule named
 * body/text/message/content/msg/html exists here.
 */
final class SmsTemplatesController extends Controller
{
    public function __construct(
        private readonly TemplateReader $reader,
        private readonly TemplateWriter $writer,
        private readonly TemplatePublisher $publisher,
        private readonly TestSend $testSend,
    ) {}

    public function index(): JsonResponse
    {
        return new JsonResponse([
            'events' => $this->reader->grouped(),
            'languages' => $this->reader->languages(),
            'incomplete' => $this->reader->incompleteEnabledEvents(),
        ]);
    }

    public function show(string $templateId): JsonResponse
    {
        $template = $this->template($templateId);

        return new JsonResponse($this->summary($template) + ['history' => $this->reader->history($template)]);
    }

    public function store(Request $request): JsonResponse
    {
        $input = $this->input($request, [
            'event' => ['required', 'string', 'max:64'],
            'language' => ['required', 'string', 'max:12'],
            'name' => ['required', 'string', 'max:120'],
            'template_body' => ['required', 'string', 'max:'.TemplatePublisher::MAX_BODY_CHARS],
            'quiet_hours_policy' => ['sometimes', 'in:exempt,hold,skip'],
            'on_missing' => ['sometimes', 'in:skip_send,fallback_text'],
            'fallback_text' => ['sometimes', 'nullable', 'string', 'max:'.TemplatePublisher::MAX_BODY_CHARS],
        ]);
        if ($input instanceof JsonResponse) {
            return $input;
        }
        /** @var array{event: string, language: string, name: string, template_body: string, quiet_hours_policy?: string, on_missing?: string, fallback_text?: ?string} $input */
        try {
            $template = $this->writer->create($input['event'], $input['language'], $input['name'], $input['template_body'], $this->actor($request), $input);
        } catch (TemplateRefused $e) {
            return $this->refused($e);
        }

        return new JsonResponse($this->summary($template), 201);
    }

    public function update(Request $request, string $templateId): JsonResponse
    {
        $template = $this->template($templateId);
        $input = $this->input($request, [
            'name' => ['sometimes', 'string', 'max:120'],
            'active' => ['sometimes', 'boolean'],
            'quiet_hours_policy' => ['sometimes', 'in:exempt,hold,skip'],
            'on_missing' => ['sometimes', 'in:skip_send,fallback_text'],
            'fallback_text' => ['sometimes', 'nullable', 'string', 'max:'.TemplatePublisher::MAX_BODY_CHARS],
        ]);
        if ($input instanceof JsonResponse) {
            return $input;
        }
        /** @var array{name?: string, active?: bool, quiet_hours_policy?: string, on_missing?: string, fallback_text?: ?string} $input */

        return new JsonResponse($this->summary($this->writer->update($template, $input, $this->actor($request))));
    }

    /** FR-05: the editor's live validation — the same code publish runs (no audit: nothing changes). */
    public function validateDraft(Request $request, string $templateId): JsonResponse
    {
        $template = $this->template($templateId);
        $input = $this->input($request, ['template_body' => ['required', 'string', 'max:'.TemplatePublisher::MAX_BODY_CHARS]]);
        if ($input instanceof JsonResponse) {
            return $input;
        }
        /** @var array{template_body: string} $input */
        try {
            return new JsonResponse(['ok' => true] + $this->publisher->validate($template->event, $input['template_body']));
        } catch (TemplateRefused $e) {
            return $this->refused($e);
        }
    }

    public function publish(Request $request, string $templateId): JsonResponse
    {
        $template = $this->template($templateId);
        $input = $this->input($request, ['template_body' => ['required', 'string', 'max:'.TemplatePublisher::MAX_BODY_CHARS], 'note' => ['sometimes', 'nullable', 'string', 'max:200']]);
        if ($input instanceof JsonResponse) {
            return $input;
        }
        /** @var array{template_body: string, note?: ?string} $input */
        try {
            $version = $this->writer->publish($template, $input['template_body'], $this->actor($request), $input['note'] ?? null);
        } catch (TemplateRefused $e) {
            return $this->refused($e);
        }

        return new JsonResponse(['version' => (int) $version->version, 'encoding' => $version->encoding, 'segments_estimate' => (int) $version->segments_estimate], 201);
    }

    public function rollback(Request $request, string $templateId): JsonResponse
    {
        $template = $this->template($templateId);
        $input = $this->input($request, ['to_version' => ['required', 'integer', 'min:1']]);
        if ($input instanceof JsonResponse) {
            return $input;
        }
        /** @var array{to_version: int} $input */
        try {
            $version = $this->writer->rollback($template, $input['to_version'], $this->actor($request));
        } catch (TemplateRefused $e) {
            return $this->refused($e);
        }

        return new JsonResponse(['version' => (int) $version->version, 'rolled_back_to' => $input['to_version']], 201);
    }

    public function destroy(Request $request, string $templateId): JsonResponse
    {
        $this->writer->delete($this->template($templateId), $this->actor($request));

        return new JsonResponse(['deleted' => true]);
    }

    /** FR-05 test-send: a template VERSION + a typed number; sandbox-tagged; never a customer. */
    public function testSend(Request $request, string $templateId): JsonResponse
    {
        $template = $this->template($templateId);
        $input = $this->input($request, ['phone' => ['required', 'string', 'regex:/^\+[1-9]\d{6,14}$/'], 'version' => ['sometimes', 'integer', 'min:1']]);
        if ($input instanceof JsonResponse) {
            return $input;
        }
        /** @var array{phone: string, version?: int} $input */
        $version = isset($input['version'])
            ? SmsTemplateVersion::query()->where('template_id', $template->getKey())->where('version', $input['version'])->first()
            : ($template->live_version_id !== null ? SmsTemplateVersion::query()->find($template->live_version_id) : null);
        if (! $version instanceof SmsTemplateVersion) {
            return $this->refused(new TemplateRefused('version_not_found', 'no published version to test'));
        }
        try {
            $sms = $this->testSend->send($version, $input['phone'], $this->actor($request));
        } catch (TemplateRefused $e) {
            return $this->refused($e);
        }

        return new JsonResponse(['sms_message_id' => $sms->identifier(), 'is_test' => true, 'state' => $sms->state, 'segments' => $sms->segments, 'encoding' => $sms->encoding], 201);
    }

    private function template(string $templateId): SmsTemplate
    {
        $template = $this->reader->find($templateId);
        if ($template === null) {
            throw new NotFoundHttpException; // C-10: the 404 pattern
        }

        return $template;
    }

    /** @return array<string, mixed> */
    private function summary(SmsTemplate $template): array
    {
        $live = $template->live_version_id !== null ? SmsTemplateVersion::query()->find($template->live_version_id) : null;

        return [
            'id' => $template->identifier(),
            'event' => $template->event,
            'language' => $template->language,
            'name' => $template->name,
            'active' => (bool) $template->active,
            'quiet_hours_policy' => $template->quiet_hours_policy,
            'on_missing' => $template->on_missing,
            'fallback_text' => $template->fallback_text,
            'live_version' => $live?->version !== null ? (int) $live->version : null,
            'template_body' => $live?->body,
            'encoding' => $live?->encoding,
            'segments_estimate' => $live?->segments_estimate !== null ? (int) $live->segments_estimate : null,
        ];
    }

    /**
     * The house shape (MergeController): a validation failure renders the C-04 problem
     * itself — never the default handler.
     *
     * @param  array<string, list<string>>  $rules
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

    private function actor(Request $request): string
    {
        /** @var User $user */
        $user = $request->user();

        return $user->identifier();
    }

    private function refused(TemplateRefused $e): JsonResponse
    {
        return ProblemDetails::response(
            code: ErrorCode::ValidationFailed,
            status: 422,
            detail: (string) __('sms.refused.'.$e->refusalCode),
            errors: ['refusal' => [$e->refusalCode]] + array_map(
                static fn (mixed $v): array => array_values(array_map(static fn (mixed $x): string => is_scalar($x) ? (string) $x : json_encode($x, JSON_THROW_ON_ERROR), is_array($v) ? $v : [$v])),
                $e->extra,
            ),
        );
    }
}
