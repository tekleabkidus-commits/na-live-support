<?php

declare(strict_types=1);

namespace App\Http\Controllers\Widget;

use App\Domain\Auth\Widget\WidgetVisitor;
use App\Domain\Forms\VersionReader;
use App\Domain\Webchat\Forms\FormStepValidator;
use App\Domain\Webchat\Forms\OfflineSubmission;
use App\Domain\Webchat\Visitor\VisitorConversation;
use App\Support\Problem\ErrorCode;
use App\Support\Problem\ProblemDetails;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Doc 13 T-03 `POST /api/widget/v1/forms/{versionId}/steps` — Doc 12's
 * contract rendered (FR-06 pre-chat, FR-09 shortcut forms, FR-15 offline).
 *   mode=form     validate ONE step against the immutable version; the
 *                 visitor holds the state (FR-05: nothing is minted here);
 *                 the completed answers ride the next message's envelope
 *                 (`form`) and Doc13/PreChat lands them (STOP-5C).
 *   mode=offline  the whole form, complete → OfflineSubmission (STOP-5B).
 * A version that is not published (or not this tenant's) is ABSENT (404).
 * Refusals are RFC 9457 with `errors{field => reason key}` — inline, neutral.
 */
final class WidgetFormStepsController
{
    public function __construct(
        private readonly VisitorConversation $conversations,
        private readonly VersionReader $versions,
        private readonly FormStepValidator $validator,
        private readonly OfflineSubmission $offline,
    ) {}

    public function store(Request $request, string $versionId): JsonResponse
    {
        $visitor = $request->user('widget');
        if (! $visitor instanceof WidgetVisitor) {
            throw new NotFoundHttpException;
        }
        $widget = $this->conversations->widgetFor($request);

        /** @var array{mode: string, step_index?: int, answers?: array<string, mixed>, complete?: bool} $input */
        $input = $request->validate([
            'mode' => ['required', 'in:form,offline'],
            'step_index' => ['sometimes', 'integer', 'min:0', 'max:50'],
            'answers' => ['sometimes', 'array'],
            'complete' => ['sometimes', 'boolean'],
        ]);

        try {
            $contract = $this->versions->widgetContract($versionId);
        } catch (\Throwable) {
            throw new NotFoundHttpException;
        }
        $schema = $contract['schema'];
        $answers = is_array($input['answers'] ?? null) ? $input['answers'] : [];

        if ($input['mode'] === 'offline') {
            // The offline form is ONE step (Doc 12's seeded shape); validate it whole.
            $verdict = $this->validator->validateStep($schema, 0, $answers);
            if ($verdict['errors'] !== []) {
                return ProblemDetails::response(ErrorCode::ValidationFailed, 422, 'widget.form.refused', self::errorLists($verdict['errors']));
            }
            $receipt = $this->offline->submit($widget, $visitor, $versionId, $verdict['answers']);

            return new JsonResponse($receipt, 201);
        }

        $verdict = $this->validator->validateStep($schema, (int) ($input['step_index'] ?? 0), $answers);
        if ($verdict['errors'] !== []) {
            return ProblemDetails::response(ErrorCode::ValidationFailed, 422, 'widget.form.refused', self::errorLists($verdict['errors']));
        }

        return new JsonResponse(['valid' => true, 'answers' => $verdict['answers'], 'next_step' => $verdict['next_step'], 'complete' => $verdict['complete']]);
    }

    /**
     * RFC 9457 `errors{}` is field → list of reasons; the validator answers one reason key per field.
     *
     * @param  array<string, string>  $errors
     * @return array<string, list<string>>
     */
    private static function errorLists(array $errors): array
    {
        $lists = [];
        foreach ($errors as $field => $reason) {
            $lists[$field] = [$reason];
        }

        return $lists;
    }
}
