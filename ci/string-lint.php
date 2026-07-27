<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| string-lint gate (Doc 01 FR-09, Doc 16 FR-05; invariant N-03)
|------------------------------------------------------------------------------
|
| Fails the build on user-visible string literals outside the i18n layer.
|
| What is scanned (user-visible surfaces):
|   - resources/js/** *.vue      → the <template> block only:
|       (a) element text nodes containing ≥ 2 consecutive letters
|           (Latin or Ethiopic), unless produced by an i18n call
|           ({{ t('key') }} / {{ $t('key') }} / v-t)
|       (b) literal values of user-facing attributes:
|           placeholder, title, alt, aria-label, aria-description, label
|   - resources/views/** *.blade.php → text nodes with ≥ 2 consecutive
|       letters outside {{ __(...) }} / @lang(...) i18n output
|
| Genuine exceptions live in ci/string-lint-allowlist.php with a reason per
| entry; the allowlist is exact-match per (file, string) so a new literal in
| an allowlisted file still fails.
|
| Exit codes: 0 = pass, 1 = violations found. This gate is blocking from
| Session 0 — the i18n layer not existing yet is exactly why the placeholder
| page's strings are allowlisted rather than the gate softened.
|
*/

/** @var array<string, list<string>> $allowlist file => list of exact allowed strings */
$allowlist = require __DIR__.'/string-lint-allowlist.php';

$root = dirname(__DIR__);
$violations = [];

/** @return list<string> */
function filesUnder(string $dir, string $suffix): array
{
    if (! is_dir($dir)) {
        return [];
    }
    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file instanceof SplFileInfo && str_ends_with($file->getPathname(), $suffix)) {
            $out[] = $file->getPathname();
        }
    }
    sort($out);

    return $out;
}

/** Letters (Latin or Ethiopic) — 2+ in a row means "a word a human reads". */
const WORD_RE = '[A-Za-z\x{1200}-\x{137F}]{2,}';

/** @param array<string, list<string>> $allowlist */
function isAllowed(string $relative, string $text, array $allowlist): bool
{
    $text = trim($text);
    foreach ($allowlist[$relative] ?? [] as $allowed) {
        if ($text === $allowed) {
            return true;
        }
    }

    return false;
}

/**
 * @param  array<string, list<string>>  $allowlist
 * @param  list<string>  $violations
 */
function checkTemplateText(string $template, string $relative, array $allowlist, array &$violations): void
{
    // (a) text nodes: text between > and < that is not an i18n interpolation.
    if (preg_match_all('/>([^<>{]*'.WORD_RE.'[^<>{]*)</u', $template, $m, PREG_OFFSET_CAPTURE) > 0) {
        foreach ($m[1] as [$text, $offset]) {
            $line = substr_count(substr($template, 0, (int) $offset), "\n") + 1;
            if (! isAllowed($relative, $text, $allowlist)) {
                $violations[] = sprintf('%s:%d: hardcoded text node "%s"', $relative, $line, trim($text));
            }
        }
    }

    // (b) user-facing attribute literals (a bound :placeholder="t('k')" is fine).
    $attrs = 'placeholder|title|alt|aria-label|aria-description|label';
    if (preg_match_all('/(?<![:\w-])('.$attrs.')="([^"]*'.WORD_RE.'[^"]*)"/u', $template, $m, PREG_OFFSET_CAPTURE) > 0) {
        foreach ($m[2] as $i => [$text, $offset]) {
            $line = substr_count(substr($template, 0, (int) $offset), "\n") + 1;
            $attr = $m[1][$i][0];
            if (! isAllowed($relative, $text, $allowlist)) {
                $violations[] = sprintf('%s:%d: hardcoded %s attribute "%s"', $relative, $line, $attr, trim($text));
            }
        }
    }
}

// --- Vue SFC templates -------------------------------------------------------
foreach (filesUnder($root.'/resources/js', '.vue') as $path) {
    $relative = substr($path, strlen($root) + 1);
    $contents = (string) file_get_contents($path);
    if (preg_match('/<template>(.*)<\/template>/su', $contents, $m) !== 1) {
        continue;
    }
    checkTemplateText($m[1], $relative, $allowlist, $violations);
}

// --- Blade views -------------------------------------------------------------
foreach (filesUnder($root.'/resources/views', '.blade.php') as $path) {
    $relative = substr($path, strlen($root) + 1);
    $contents = (string) file_get_contents($path);
    // Strip blade comments, directives and echo blocks; then apply the same
    // text-node rule.
    $stripped = (string) preg_replace(['/\{\{--.*?--\}\}/s', '/\{\{.*?\}\}/s', '/@\w+(\([^)]*\))?/'], '', $contents);
    checkTemplateText($stripped, $relative, $allowlist, $violations);
}

// --- Verdict -----------------------------------------------------------------
if ($violations !== []) {
    foreach ($violations as $v) {
        fwrite(STDERR, "string-lint: $v\n");
    }
    fwrite(STDERR, sprintf(
        'string-lint: FAIL (%d violation(s)). User-visible strings must be i18n keys '
        ."(Doc 01 FR-09). Genuine exceptions go in ci/string-lint-allowlist.php with a reason.\n",
        count($violations),
    ));
    exit(1);
}

echo "string-lint: PASS (no user-visible literals outside the i18n layer)\n";
exit(0);
