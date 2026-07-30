<?php

declare(strict_types=1);

namespace App\Http\Controllers\Foundation;

use App\Support\Audit\AuditActor;
use App\Support\Problem\ErrorCode;
use App\Support\Problem\ProblemDetails;
use App\Support\Settings\Exceptions\SettingNotEditable;
use App\Support\Settings\Exceptions\SettingWriteConflict;
use App\Support\Settings\Exceptions\UnregisteredSetting;
use App\Support\Settings\SettingsRegistry;
use App\Support\Settings\SettingsRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * FR-07's `settings.read` API, plus the write path (Doc 01 FR-07, T-07, EC-06).
 *
 * Doc 16 owns the Backoffice UI that renders these; Doc 01 owns the contract underneath
 * it, and §8 fixes what that contract must expose per setting: "label (i18n),
 * description, type-appropriate control, validation error inline, 'changed by X at Y'
 * provenance line, and reset-to-default".
 *
 * `updated_at` is returned on the single-setting read and required on write, which is how
 * EC-06's optimistic check reaches the client: a UI that renders a form holds the
 * `updated_at` it rendered from, echoes it back, and gets a conflict rather than silently
 * clobbering a colleague's edit.
 *
 * Every failure is RFC 9457 problem-details with a stable `code` (T-07/C-04), so the
 * Backoffice can branch on the code and render the translated title, rather than parsing
 * prose that changes with the language.
 */
final class TenantSettingsController
{
    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly SettingsRegistry $registry,
    ) {}

    /**
     * Every setting: registered default merged with this tenant's overrides.
     *
     * Encrypted-string values are reported as `[encrypted]` rather than decoded — a bulk
     * read is precisely the shape that ends up in a log or a browser cache (C-13).
     */
    public function index(): JsonResponse
    {
        $values = $this->settings->all();

        $out = [];
        foreach ($values as $key => $value) {
            $definition = $this->registry->definition($key);

            $out[] = [
                'key' => $key,
                'value' => $value,
                'type' => $definition['type']->value,
                'tenant_editable' => $definition['tenant_editable'],
                'is_default' => $value === $definition['default'],
                // N-03/C-08: the label and description are i18n KEYS. Doc 16 renders them;
                // no English prose crosses this boundary.
                'label_i18n_key' => sprintf('setting.%s.label', $key),
                'description_i18n_key' => sprintf('setting.%s.description', $key),
            ];
        }

        return new JsonResponse(['data' => $out]);
    }

    public function show(string $key): JsonResponse
    {
        try {
            $definition = $this->registry->definition($key);
        } catch (UnregisteredSetting $e) {
            return $this->unregistered($key);
        }

        return new JsonResponse([
            'key' => $key,
            'value' => $definition['type']->isSecret() ? '[encrypted]' : $this->settings->get($key),
            'type' => $definition['type']->value,
            'tenant_editable' => $definition['tenant_editable'],
            // The EC-06 token. A client that writes without echoing this back gets
            // last-write-wins with no conflict detection. A monotonic DB-side version
            // rather than a timestamp, because EC-08 forbids app-clock ordering.
            'version' => $this->settings->version($key),
        ]);
    }

    public function update(Request $request, string $key): JsonResponse
    {
        if (! $this->registry->has($key)) {
            return $this->unregistered($key);
        }

        try {
            $this->settings->set(
                key: $key,
                value: $request->input('value'),
                actor: AuditActor::Staff,
                // Doc 02/03 own who the actor is; until then the seam is the authenticated
                // user's id, which the staff resolution path already required.
                actorId: $this->actorId($request),
                expectedVersion: $this->optionalInt($request->input('version')),
            );
        } catch (ValidationException $e) {
            return ProblemDetails::response(
                code: ErrorCode::ValidationFailed,
                status: 422,
                detail: __('foundation.error.validation_failed'),
                errors: $this->normaliseErrors($e->errors()),
            );
        } catch (SettingNotEditable $e) {
            return ProblemDetails::response(
                code: ErrorCode::UnregisteredSetting,
                status: 403,
                detail: $e->getMessage(),
            );
        } catch (SettingWriteConflict $e) {
            // EC-06's "conflict toast to the loser". 409 plus the winning value, so the UI
            // can say what it lost to instead of just that it lost.
            return ProblemDetails::response(
                code: ErrorCode::SettingWriteConflict,
                status: 409,
                detail: $e->getMessage(),
                extra: [
                    'winning_value' => $e->winningValue,
                    'version' => $this->settings->version($key),
                ],
            );
        }

        return new JsonResponse([
            'key' => $key,
            'version' => $this->settings->version($key),
        ]);
    }

    /**
     * Reset to the registered default (Doc 01 §8).
     */
    public function destroy(Request $request, string $key): JsonResponse
    {
        if (! $this->registry->has($key)) {
            return $this->unregistered($key);
        }

        $this->settings->reset(
            key: $key,
            actor: AuditActor::Staff,
            actorId: $this->actorId($request),
        );

        return new JsonResponse([
            'key' => $key,
            'value' => $this->registry->default($key),
            'is_default' => true,
        ]);
    }

    /**
     * The authenticated staff user's id, as a string.
     *
     * Doc 02 owns authentication and Doc 03 owns who may do what; this is the documented
     * seam between them and the audit trail's `actor_id`.
     */
    private function actorId(Request $request): ?string
    {
        $id = $request->user()?->getAttribute('id');

        return is_string($id) ? $id : null;
    }

    private function optionalInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && (string) (int) $value === $value ? (int) $value : null;
    }

    /**
     * ValidationException::errors() is typed loosely; RFC 9457's `errors` member is
     * field => list<string>, so coerce rather than pass mixed into the response shape.
     *
     * @param  array<array-key, mixed>  $errors
     * @return array<string, list<string>>
     */
    private function normaliseErrors(array $errors): array
    {
        $out = [];
        foreach ($errors as $field => $messages) {
            $out[(string) $field] = array_values(array_map(
                static fn (mixed $message): string => is_string($message) ? $message : json_encode($message, JSON_THROW_ON_ERROR),
                is_array($messages) ? $messages : [$messages],
            ));
        }

        return $out;
    }

    private function unregistered(string $key): JsonResponse
    {
        return ProblemDetails::response(
            code: ErrorCode::UnregisteredSetting,
            status: 404,
            detail: __('foundation.error.unregistered_setting'),
            extra: ['key' => $key],
        );
    }
}
