<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sms;

use App\Domain\Sms\OptOut\OptOutRegistry;
use App\Domain\Sms\Support\PhoneMask;
use App\Http\Controllers\Controller;
use App\Models\SmsOptOut;
use App\Models\User;
use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditLogger;
use App\Support\Problem\ErrorCode;
use App\Support\Problem\ProblemDetails;
use App\Support\Rbac\CapabilityMapProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * §8's opt-out manager doors (FR-14, SEC-04): list (masked display — the
 * B4-S08 form), add-by-staff with a reason, the high-visibility restore with
 * a reason, CSV import (`sms.optout.manage`, rows as source `api`), CSV
 * export (`customers.export`, AUDITED, masked BY DEFAULT — full numbers only
 * for a caller who also holds `sms.optout.manage`).
 */
final class SmsOptOutsController extends Controller
{
    public function __construct(
        private readonly OptOutRegistry $registry,
        private readonly AuditLogger $audit,
        private readonly CapabilityMapProvider $verdicts,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $rows = [];
        foreach (SmsOptOut::query()->orderByDesc('id')->limit(100)->get() as $row) {
            $rows[] = [
                'id' => $row->identifier(),
                'phone_masked' => PhoneMask::display($row->phone),
                'source' => $row->source,
                'reason' => $row->reason,
                'stop_word_matched' => $row->stop_word_matched,
                'restored_at' => $row->restored_at?->toIso8601String(),
                'restore_reason' => $row->restore_reason,
            ];
        }

        return new JsonResponse(['optouts' => $rows]);
    }

    public function store(Request $request): JsonResponse
    {
        $input = $this->input($request, [
            'phone' => ['required', 'string', 'regex:/^\+[1-9]\d{6,14}$/'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);
        if ($input instanceof JsonResponse) {
            return $input;
        }
        /** @var array{phone: string, reason: string} $input */
        $optoutId = $this->registry->addByStaff($input['phone'], $input['reason'], $this->actor($request));
        if ($optoutId === null) {
            $request->attributes->set('audit.noop', true); // already opted out — nothing to add

            return new JsonResponse(['already_opted_out' => true], 200);
        }

        return new JsonResponse(['optout_id' => $optoutId], 201);
    }

    public function restore(Request $request, string $optoutId): JsonResponse
    {
        $input = $this->input($request, ['reason' => ['required', 'string', 'min:3', 'max:500']]);
        if ($input instanceof JsonResponse) {
            return $input;
        }
        /** @var array{reason: string} $input */
        try {
            $this->registry->restore($optoutId, $input['reason'], $this->actor($request));
        } catch (\InvalidArgumentException $e) {
            return ProblemDetails::response(ErrorCode::ValidationFailed, 422, $e->getMessage(), errors: ['refusal' => ['restore_refused']]);
        }

        return new JsonResponse(['restored' => true]);
    }

    /** SEC-04: the export is customers.export-classed, audited, and MASKED unless the caller also manages opt-outs. */
    public function export(Request $request): StreamedResponse
    {
        $full = $this->can($request, 'sms.optout.manage');
        $rows = SmsOptOut::query()->orderBy('id')->get();
        $exportId = (string) \Illuminate\Support\Str::uuid7();
        $this->audit->record('sms.optout.exported', 'sms_optout', $exportId, AuditActor::Staff, ['export_id' => $exportId, 'row_count' => $rows->count(), 'masked' => ! $full], $this->actor($request));

        return new StreamedResponse(function () use ($rows, $full): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fputcsv($out, ['phone', 'source', 'added_at', 'restored_at']);
            foreach ($rows as $row) {
                fputcsv($out, [
                    $full ? $row->phone : PhoneMask::display($row->phone),
                    $row->source,
                    (string) $row->created_at, // created_at is the DB clock's (no model cast rides it)
                    $row->restored_at?->toIso8601String() ?? '',
                ]);
            }
            fclose($out);
        }, 200, ['Content-Type' => 'text/csv', 'Content-Disposition' => 'attachment; filename="sms-optouts.csv"']);
    }

    /** Import: one E.164 per line (a `phone` CSV header allowed); rows land as source `api`. */
    public function import(Request $request): JsonResponse
    {
        $input = $this->input($request, ['csv' => ['required', 'string', 'max:1048576']]);
        if ($input instanceof JsonResponse) {
            return $input;
        }
        /** @var array{csv: string} $input */
        $added = 0;
        $skipped = 0;
        $invalid = 0;
        foreach (preg_split('/\r\n|\r|\n/', $input['csv']) ?: [] as $line) {
            $phone = trim($line, " \t\"");
            if ($phone === '' || strcasecmp($phone, 'phone') === 0) {
                continue;
            }
            if (preg_match('/^\+[1-9]\d{6,14}$/', $phone) !== 1) {
                $invalid++;

                continue;
            }
            $this->registry->addFromImport($phone, $this->actor($request)) === null ? $skipped++ : $added++;
        }
        $importId = (string) \Illuminate\Support\Str::uuid7();
        $this->audit->record('sms.optout.imported', 'sms_optout', $importId, AuditActor::Staff, ['import_id' => $importId, 'row_count' => $added, 'already_opted_out' => $skipped, 'invalid' => $invalid], $this->actor($request));

        return new JsonResponse(['added' => $added, 'already_opted_out' => $skipped, 'invalid' => $invalid], 201);
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

    private function actor(Request $request): string
    {
        /** @var User $user */
        $user = $request->user();

        return $user->identifier();
    }

    private function can(Request $request, string $permission): bool
    {
        $user = $request->user();

        return $user instanceof User && $this->verdicts->mapFor($user)->allows($permission);
    }
}
