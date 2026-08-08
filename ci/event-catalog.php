<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| event-catalog gate (Doc 01 FR-08, §21; R-02)
|------------------------------------------------------------------------------
|
| Doc 01 §21: "Document every published event in a living catalog (name, payload
| schema, producers, consumers) checked in CI — this catalog becomes the
| integration contract for Docs 04–17."
|
| CODE IS THE SOURCE OF TRUTH; docs/EVENT-CATALOG.md is generated from it. This
| gate regenerates the document and fails if the checked-in copy differs.
|
| WHY GENERATE RATHER THAN LINT A HANDWRITTEN FILE. A catalog maintained by hand
| beside the code drifts from it within weeks, and a drifted integration contract
| is worse than none — a consumer team reads a payload shape that no producer
| emits. R-02 ("event bus becomes implicit coupling") begins exactly there. The
| only catalog that stays true is one nobody can forget to update.
|
| Not a seventh registry (CLAUDE.md §2 fixes those at six): this is a code-side
| contract with a generated document and a gate.
|
| `--write` regenerates. Without it, the gate only checks — CI must never rewrite
| the tree it is inspecting.
|
| Exit codes: 0 = in sync, 1 = drift (or missing).
|
*/

$root = dirname(__DIR__);
require $root.'/vendor/autoload.php';

/** @var Illuminate\Foundation\Application $app */
$app = require $root.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$catalogPath = $root.'/docs/EVENT-CATALOG.md';
$write = in_array('--write', $argv, true);

// Through the class's TYPED accessors, not the raw const: past ~70 entries PHPStan
// generalizes a constant array's per-entry shape, and the definition() @return is the
// precise contract this generator needs (and the one every other consumer gets).
$names = App\Support\Events\EventCatalog::names();
sort($names);

$lines = [];
$lines[] = '# Event catalog';
$lines[] = '';
$lines[] = '> **GENERATED — do not edit.** Source of truth is';
$lines[] = '> `app/Support/Events/EventCatalog.php`. Regenerate with';
$lines[] = '> `php ci/event-catalog.php --write`; the `event-catalog` CI gate fails on drift.';
$lines[] = '';
$lines[] = 'This is the integration contract between the module that publishes an event and every';
$lines[] = 'module that consumes it (Doc 01 §21). A producer changing a payload can see here who';
$lines[] = 'will notice; a consumer can see what it is entitled to receive.';
$lines[] = '';
$lines[] = '**Payload keys are exhaustive and enforced.** `EventPublisher` rejects a payload with a';
$lines[] = 'missing key AND one with an extra key — the second is how PII leaks into an event stream';
$lines[] = 'that is retained for 13 months (SEC-07, NFR-07).';
$lines[] = '';
$lines[] = sprintf('%d event(s).', count($names));
$lines[] = '';

foreach ($names as $name) {
    $definition = App\Support\Events\EventCatalog::definition($name);
    $lines[] = sprintf('## `%s`', $name);
    $lines[] = '';
    $lines[] = $definition['description'];
    $lines[] = '';
    $lines[] = sprintf('- **Schema version:** %d', $definition['schema_version']);
    $lines[] = sprintf('- **Payload:** %s', implode(', ', array_map(
        static fn (string $key): string => sprintf('`%s`', $key),
        $definition['payload'],
    )));
    $lines[] = sprintf('- **Producers:** %s', implode(', ', $definition['producers']));
    $lines[] = sprintf('- **Consumers:** %s', implode(', ', $definition['consumers']));
    $lines[] = '';
}

$generated = implode("\n", $lines);

if ($write) {
    file_put_contents($catalogPath, $generated);
    printf("event-catalog: WROTE %s (%d event(s))\n", 'docs/EVENT-CATALOG.md', count($names));
    exit(0);
}

if (! is_file($catalogPath)) {
    fwrite(STDERR, "event-catalog: FAIL — docs/EVENT-CATALOG.md is missing. Run `php ci/event-catalog.php --write`.\n");
    exit(1);
}

$onDisk = (string) file_get_contents($catalogPath);

if ($onDisk !== $generated) {
    fwrite(STDERR,
        'event-catalog: FAIL — docs/EVENT-CATALOG.md is out of sync with '
        ."App\\Support\\Events\\EventCatalog.\n"
        .'The catalog is the integration contract for Docs 04-17; a stale one tells a consumer '
        ."team a payload shape no producer emits.\n"
        ."Run: php ci/event-catalog.php --write\n"
    );
    exit(1);
}

printf("event-catalog: PASS (%d event(s) in sync)\n", count($names));
exit(0);
