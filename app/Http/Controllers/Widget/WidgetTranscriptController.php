<?php

declare(strict_types=1);

namespace App\Http\Controllers\Widget;

use App\Domain\Auth\Widget\WidgetVisitor;
use App\Domain\Webchat\Visitor\VisitorConversation;
use App\Http\Controllers\Controller;
use App\Support\Problem\ErrorCode;
use App\Support\Problem\ProblemDetails;
use App\Support\Settings\SettingsRepository;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Doc 13 SEC-06 (S13 step 12): the visitor's OWN transcript as a text download — the same rows
 * the thread door serves (customer-visible messages only: `is_internal = false`, not quarantined,
 * not deleted), so internal notes are ABSENT by construction (Doc 04 BR-01: a note is a different
 * type that never reaches the adapter — it cannot appear here). Frame text is localized through
 * `widget.transcript.*`; the content is verbatim. Rate-limited per visitor by
 * `widget.transcript.rate_per_hour`; `widget.transcript.mode = off` closes the door (404, C-10).
 * Email delivery is Doc 16's template family — not built here ([D16], stated).
 */
final class WidgetTranscriptController extends Controller
{
    public function __construct(
        private readonly VisitorConversation $conversations,
        private readonly SettingsRepository $settings,
        private readonly RateLimiter $limiter,
    ) {}

    public function show(Request $request): Response
    {
        $mode = $this->settings->get('widget.transcript.mode');
        if ($mode !== 'download') {
            throw new NotFoundHttpException; // `email` is Doc 16's delivery ([D16]): no download door for this operator
        }
        $visitor = $request->user('widget');
        if (! $visitor instanceof WidgetVisitor) {
            throw new NotFoundHttpException;
        }
        $widget = $this->conversations->widgetFor($request);
        $conversation = $this->conversations->find($widget, $visitor);
        if ($conversation === null) {
            throw new NotFoundHttpException;
        }
        $perHour = $this->settings->get('widget.transcript.rate_per_hour');
        $perHour = is_numeric($perHour) ? max(1, (int) $perHour) : 3;
        $key = 'widget-transcript:'.$visitor->visitorId;
        if ($this->limiter->tooManyAttempts($key, $perHour)) {
            return ProblemDetails::response(ErrorCode::RateLimited, 429, 'widget.transcript.rate_limited', extra: ['retry_after_seconds' => $this->limiter->availableIn($key)]);
        }
        $this->limiter->hit($key, 3600);

        $language = in_array($request->query('language'), ['en', 'am'], true) ? (string) $request->query('language') : 'en';
        $t = function (string $k, array $vars = []) use ($language): string {
            return $this->frame($language, $k, $vars);
        };
        $tz = $this->settings->get('tenant.display_timezone');
        $tz = is_string($tz) && $tz !== '' ? $tz : 'UTC';
        $identityMode = $this->settings->get('widget.agent_identity');

        $rows = DB::table('messages')
            ->where('tenant_id', $conversation->getAttribute('tenant_id'))
            ->where('conversation_id', $conversation->identifier())
            ->where('is_internal', false)
            ->where('quarantined', false)
            ->whereNull('deleted_at')
            ->orderBy('created_at')->orderBy('id')
            ->get(['created_at', 'author_type', 'author_id', 'kind', 'body', 'media']);

        $str = static fn (mixed $v): string => is_scalar($v) ? (string) $v : '';
        $lines = [$t('title'), $t('exported', ['at' => CarbonImmutable::now('UTC')->setTimezone($tz)->format('Y-m-d H:i')]), $t('note'), ''];
        $staffNames = [];
        foreach ($rows as $row) {
            $who = match ($row->author_type) {
                'customer' => $t('you'),
                'ai' => $t('assistant'),
                'agent' => $this->staffDisplay($row->author_id, $identityMode, $staffNames) ?? $t('team'),
                default => $t('system'),
            };
            $at = CarbonImmutable::parse($str($row->created_at), 'UTC')->setTimezone($tz)->format('H:i');
            $body = is_string($row->body) ? $row->body : '';
            $media = is_string($row->media) ? json_decode($row->media, true) : [];
            $attachments = is_array($media) && $media !== [] ? ' '.$t('attachment', ['count' => count($media)]) : '';
            $lines[] = sprintf('%s · %s · %s%s', $at, $who, $body, $attachments);
        }
        $text = implode("\n", $lines)."\n";

        return new Response($text, 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="transcript-'.CarbonImmutable::now('UTC')->format('Ymd-Hi').'.txt"',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * The transcript's frame text in the visitor's language (content is never translated).
     *
     * @param  array<mixed>  $vars
     */
    private function frame(string $language, string $key, array $vars): string
    {
        $safe = [];
        foreach ($vars as $name => $value) {
            if (is_string($name)) {
                $safe[$name] = is_scalar($value) ? (string) $value : '';
            }
        }
        $line = trans('widget.transcript.'.$key, $safe, $language);

        return is_string($line) ? $line : '';
    }

    /**
     * The staff line follows `widget.agent_identity` exactly as the thread does (first name / team name); never an email.
     *
     * @param  array<string, string|null>  $cache
     */
    private function staffDisplay(mixed $authorId, mixed $mode, array &$cache): ?string
    {
        if (! is_string($authorId)) {
            return null;
        }
        if (array_key_exists($authorId, $cache)) {
            return $cache[$authorId];
        }
        $display = null;
        if ($mode === 'team_name') {
            $team = DB::table('team_members as tm')->join('teams as tt', 'tt.id', '=', 'tm.team_id')->where('tm.user_id', $authorId)->value('tt.name');
            $display = is_string($team) ? $team : null;
        } else {
            $name = DB::table('users')->where('id', $authorId)->value('name');
            $display = is_string($name) && trim($name) !== '' ? explode(' ', trim($name))[0] : null;
        }

        return $cache[$authorId] = $display;
    }
}
