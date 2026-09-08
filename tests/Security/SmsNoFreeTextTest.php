<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Tests\Support\Sms\SmugglesBodyControl;

/*
| Doc 14 BR-01 / FR-02 / §3 / Q1 — NOTIFICATION-ONLY IS STRUCTURAL.
|
| "Free-text SMS does not exist at any permission level." Every send door
| accepts an EVENT + a TEMPLATE REFERENCE + ids, never text. This sweep is the
| structural assertion (STOP-R 3), sequenced BEFORE the first send door and
| run at every step that adds one; it is also the `sms-no-free-text` CI gate's
| body (registered at step 2b). Sabotage (a): a `body` field planted on a
| request → red.
|
| The sweep covers THE DOORS — every class under the namespaces a send can
| enter through: App\Domain\Sms\{Pipeline,Consumers,Broadcasts,OptOut,Otp},
| App\Jobs\Sms, App\Http\Requests\Sms, App\Http\Controllers\Sms, the
| Inbox controllers carrying "Sms" in their name, App\Console\Commands\Sms —
| every constructor parameter (declared by the class itself), every public
| method parameter, every public property, and any FormRequest's rule keys;
| plus every route whose name starts with `sms.` or contains `.sms.`. A
| parameter or rule named body / text / message / content / msg / html that is
| a STRING (or untyped, or mixed) is a violation. A parameter of a domain TYPE
| (e.g. an `SmsMessage $message`) is not text and passes.
|
| Deliberately OUTSIDE the sweep, by name: App\Domain\Sms\Encoding (the
| segmenter is arithmetic over a string), Rendering (the resolver produces the
| rendered body from a PUBLISHED template — FR-06), Templates (operator content
| written through the `sms.templates.*` doors — FR-04's design; the template
| body is not a per-send text). None of these is a send door; a send door that
| accepted text would be caught in the namespaces above. Until step 5 lands
| the first door the class half has nothing to sweep and the route half + the
| positive control carry the proof; from step 5 the class half must be
| non-empty (the assertion below flips when Pipeline exists).
*/

/** @return list<class-string> */
function smsSweepClasses(): array
{
    $classes = [];
    foreach (['app/Domain/Sms/Pipeline', 'app/Domain/Sms/Consumers', 'app/Domain/Sms/Broadcasts', 'app/Domain/Sms/OptOut', 'app/Domain/Sms/Otp', 'app/Jobs/Sms', 'app/Http/Requests/Sms', 'app/Http/Controllers/Sms', 'app/Http/Controllers/Inbox', 'app/Console/Commands/Sms'] as $dir) {
        $path = base_path($dir);
        if (! is_dir($path)) {
            continue;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path));
        foreach ($it as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $relative = str_replace(base_path().'/app/', '', $file->getPathname());
            /** @var class-string $class */
            $class = 'App\\'.str_replace(['/', '.php'], ['\\', ''], $relative);
            if ($dir === 'app/Http/Controllers/Inbox' && ! str_contains($class, 'Sms')) {
                continue;
            }
            if (class_exists($class)) {
                $classes[] = $class;
            }
        }
    }

    return $classes;
}

const SMS_FREE_TEXT_NAMES = ['body', 'text', 'message', 'content', 'msg', 'html'];

function smsIsTextLike(?ReflectionType $type): bool
{
    if ($type === null) {
        return true;
    }
    $names = $type instanceof ReflectionUnionType
        ? array_map(static fn (ReflectionNamedType $t): string => $t->getName(), $type->getTypes())
        : [$type instanceof ReflectionNamedType ? $type->getName() : (string) $type];

    foreach ($names as $name) {
        if (in_array($name, ['string', 'mixed', 'array'], true)) {
            return true;
        }
    }

    return false;
}

/** @return list<string> violations as "Class::member" */
function smsFreeTextViolations(string $class): array
{
    $violations = [];
    $ref = new ReflectionClass($class);

    $params = [];
    $constructor = $ref->getConstructor();
    if ($constructor !== null && $constructor->getDeclaringClass()->getName() === $class) {
        $params = $constructor->getParameters();
    }
    foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if ($method->getDeclaringClass()->getName() !== $class || $method->isConstructor()) {
            continue;
        }
        foreach ($method->getParameters() as $p) {
            $params[] = $p;
        }
    }
    foreach ($params as $p) {
        if (in_array(strtolower($p->getName()), SMS_FREE_TEXT_NAMES, true) && smsIsTextLike($p->getType())) {
            $violations[] = "{$class}::\${$p->getName()}";
        }
    }
    foreach ($ref->getProperties(ReflectionProperty::IS_PUBLIC) as $prop) {
        if (in_array(strtolower($prop->getName()), SMS_FREE_TEXT_NAMES, true) && smsIsTextLike($prop->getType())) {
            $violations[] = "{$class}::\${$prop->getName()}";
        }
    }
    if ($ref->hasMethod('rules') && $ref->isSubclassOf(Illuminate\Foundation\Http\FormRequest::class) && $ref->isInstantiable()) {
        /** @var array<string, mixed> $rules */
        $rules = $ref->newInstanceWithoutConstructor()->rules();
        foreach (array_keys($rules) as $key) {
            $leaf = strtolower((string) preg_replace('/^.*\./', '', (string) $key));
            if (in_array($leaf, SMS_FREE_TEXT_NAMES, true)) {
                $violations[] = "{$class}::rules[{$key}]";
            }
        }
    }

    return array_values(array_unique($violations));
}

test('no class in the SMS namespaces accepts a free-text field (BR-01, structural)', function () {
    $classes = smsSweepClasses();
    $violations = [];
    foreach ($classes as $class) {
        $violations = [...$violations, ...smsFreeTextViolations($class)];
    }

    if (is_dir(base_path('app/Domain/Sms/Pipeline'))) {
        expect($classes)->not->toBeEmpty('the doors exist but the sweep found no class — it would prove nothing');
    }
    expect($violations)->toBe([]);
});

test('no route named sms.* (or *.sms.*) binds a controller parameter or a request rule that carries text', function () {
    $violations = [];
    foreach (Route::getRoutes() as $route) {
        $name = (string) $route->getName();
        if (! (str_starts_with($name, 'sms.') || str_contains($name, '.sms.'))) {
            continue;
        }
        $action = $route->getActionName();
        if (! str_contains($action, '@')) {
            continue;
        }
        [$class, $method] = explode('@', $action, 2);
        if (! class_exists($class)) {
            continue;
        }
        foreach ((new ReflectionMethod($class, $method))->getParameters() as $p) {
            if (in_array(strtolower($p->getName()), SMS_FREE_TEXT_NAMES, true) && smsIsTextLike($p->getType())) {
                $violations[] = "{$name} → {$class}::{$method}(\${$p->getName()})";
            }
            $type = $p->getType();
            if ($type instanceof ReflectionNamedType && ! $type->isBuiltin() && class_exists($type->getName())) {
                $violations = [...$violations, ...smsFreeTextViolations($type->getName())];
            }
        }
    }

    expect($violations)->toBe([]);
});

test('POSITIVE CONTROL — a request that smuggles a `body` is caught by the same sweep (the gate must be seen to fail)', function () {
    $violations = smsFreeTextViolations(SmugglesBodyControl::class);

    expect($violations)->toBe([
        SmugglesBodyControl::class.'::$body',
        SmugglesBodyControl::class.'::$text',
        SmugglesBodyControl::class.'::rules[msg]',
    ]);
});
