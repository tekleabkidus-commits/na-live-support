<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Inbox\ReadModels\ListProjector;
use App\Domain\Tickets\Tags\TagRewrite;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Problem\ErrorCode;
use App\Support\Problem\ProblemDetails;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * R-22's class-2 fill (S07 step 14) — the tag rename/merge SURFACE over Doc
 * 06's T-07 engine, exactly as the resolved ruling orders: "existing
 * design-system components (settings-row + confirmation patterns); no
 * design-track reopen". The engine stays the owning machinery — chunked,
 * resumable, audited with counts — this door only carries the operator's one
 * question to it and hands the count back.
 *
 * The same-tag noop is made UNREACHABLE here (`different:from`): the engine
 * returns 0 before its audit write on that path, and this route DECLARES
 * ticket.tags_rewritten — the D8/A-37 lesson applied at design time rather
 * than discovered at runtime.
 */
final class TagScreensController extends Controller
{
    public function manager(Request $request): Response
    {
        return Inertia::render('Admin/Tags/TagManager', [
            't' => __('tags.ui'),
            'tags' => $this->inventory(),
        ]);
    }

    public function rewrite(Request $request, TagRewrite $engine, ListProjector $projector): JsonResponse
    {
        try {
            /** @var array{from: string, to: string} $input */
            $input = $request->validate([
                'from' => ['required', 'string', 'max:48'],
                'to' => ['required', 'string', 'max:48', 'different:from'],
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            /** @var array<string, list<string>> $errors */
            $errors = $e->errors();

            return ProblemDetails::response(ErrorCode::ValidationFailed, 422, 'invalid input', errors: $errors);
        }

        /** @var User $user */
        $user = $request->user();

        try {
            $rewritten = $engine->rename($input['from'], $input['to'], $user);
        } catch (DomainException $e) {
            return ProblemDetails::response(ErrorCode::ValidationFailed, 422, $e->getMessage());
        }

        // The historical rewrite moved ticket truth under the Doc 07
        // projection — converge it set-based (per-row recompute is the wrong
        // tool at T-07's scale).
        $projector->syncTagsFromTruth();

        return new JsonResponse([
            'ok' => true,
            'tickets_rewritten' => $rewritten,
            'tags' => $this->inventory(),
        ]);
    }

    /**
     * The tenant's tag inventory with usage counts — RLS bounds the rows; the
     * 200-cap is stated on the surface when it bites (no silent truncation).
     *
     * @return list<array{name: string, count: int}>
     */
    private function inventory(): array
    {
        /** @var list<object{name: string, count: int|string}> $rows */
        $rows = DB::select(
            <<<'SQL'
                SELECT tag.name AS name, COUNT(*) AS count
                  FROM tickets, unnest(tickets.tags) AS tag(name)
                 GROUP BY tag.name
                 ORDER BY count DESC, name ASC
                 LIMIT 200
                SQL,
        );

        return array_map(
            static fn (object $row): array => ['name' => $row->name, 'count' => (int) $row->count],
            $rows,
        );
    }
}
