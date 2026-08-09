<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Direct;

use Illuminate\Support\Facades\DB;

/**
 * FR-28: per-bot per-language operator template content with {{variable}}
 * interpolation. Variables whose data source EXISTS resolve from the passed
 * context; a variable whose data source is UNBUILT ({{queue_wait}} — the
 * wait-time estimate is Doc 11/15's, not yet built) resolves through a STATED
 * SEAM FIXTURE, never a silent blank (the plan's obligation 7).
 */
final class TemplateResolver
{
    /** FR-28 / §8's six message kinds — "complete" for the /language gate. */
    public const REQUIRED_KEYS = [
        'greeting', 'off_hours', 'handoff', 'resolved_csat', 'redirect', 'window_guidance',
    ];

    /**
     * Seam fixtures for variables whose data source is unbuilt. Each is
     * STATED here, not blank — when the owning module lands, its real value
     * replaces the fixture and this entry is removed.
     *
     * @var array<string, string>
     */
    private const SEAM_FIXTURES = [
        // queue_wait: the estimated wait is Doc 11's queue model / Doc 15's
        // metric — unbuilt. Until then the template renders this stated seam
        // value, never an empty {{queue_wait}}.
        'queue_wait' => 'a few minutes',
    ];

    /**
     * Render a bot's template in a language with the given real variables.
     * Returns null if the template does not exist.
     *
     * @param  array<string, string>  $variables  real data sources (e.g. first_name)
     */
    public function render(string $botId, string $language, string $key, array $variables): ?string
    {
        $body = DB::table('telegram_templates')
            ->where('bot_id', $botId)
            ->where('language', $language)
            ->where('template_key', $key)
            ->value('body');

        if (! is_string($body)) {
            return null;
        }

        $resolved = $variables + self::SEAM_FIXTURES;

        return (string) preg_replace_callback(
            '/\{\{\s*(\w+)\s*\}\}/',
            static fn (array $m): string => $resolved[$m[1]] ?? '{{'.$m[1].'}}',
            $body,
        );
    }
}
