<?php

declare(strict_types=1);

use App\Domain\Conversations\Adapters\DeliverableKind;
use App\Domain\Conversations\Adapters\DeliverablePayload;
use App\Domain\Conversations\Adapters\InboundSender;
use App\Domain\Conversations\Adapters\NormalizedInbound;
use App\Domain\Conversations\Messages\MessageKind;
use Carbon\CarbonImmutable;

/*
|--------------------------------------------------------------------------
| Note isolation — the STATIC/TYPE half of Doc 04 AC-04 (SEC-07, BR-01, N-04)
|--------------------------------------------------------------------------
|
| "Attempt to route a note through every adapter path fails at type level."
| The guarantee is structural: the only content type any adapter method accepts is
| DeliverablePayload, and DeliverablePayload is constructible only from DeliverableKind
| — an enum with no Note/System case. These tests pin that structure so the sabotage
| that re-adds a path (a Note case, a nullable payload, an adapter method taking a
| Message) goes red here, per release (BR-01).
|
| The RUNTIME half (the compose-path guard refusing is_internal content) lands with
| the notes feature (step 6) and extends this file.
*/

test('DeliverableKind is exactly the seven customer-content kinds — no note, no system', function () {
    $values = array_column(DeliverableKind::cases(), 'value');
    sort($values);

    expect($values)->toBe(['audio', 'document', 'photo', 'sticker', 'text', 'video', 'voice']);
});

test('the single kind-conversion point refuses note and system, and maps the rest (positive control)', function () {
    // Positive control first: the converter genuinely converts.
    expect(DeliverableKind::fromMessageKind(MessageKind::Text))->toBe(DeliverableKind::Text)
        ->and(DeliverableKind::fromMessageKind(MessageKind::Photo))->toBe(DeliverableKind::Photo);

    expect(fn () => DeliverableKind::fromMessageKind(MessageKind::Note))->toThrow(DomainException::class);
    expect(fn () => DeliverableKind::fromMessageKind(MessageKind::System))->toThrow(DomainException::class);
});

test('a channel cannot originate a note: NormalizedInbound refuses kind=note (positive control: text builds)', function () {
    $build = fn (MessageKind $kind) => new NormalizedInbound(
        externalChatId: 'fixture-chat-1',
        externalMessageId: 'fixture-in-1',
        sender: new InboundSender('fixture-user-1', 'Fixture Customer', '@fixture_customer'),
        kind: $kind,
        body: 'hello',
        media: [],
        replyToExternalMessageId: null,
        channelMeta: [],
        occurredAt: CarbonImmutable::parse('2026-08-04T07:00:00Z'),
        dedupeKey: 'fake:1',
    );

    expect($build(MessageKind::Text))->toBeInstanceOf(NormalizedInbound::class);
    expect(fn () => $build(MessageKind::Note))->toThrow(DomainException::class);
});

test('DeliverablePayload is constructible only through kind-safe named constructors', function () {
    $ctor = (new ReflectionClass(DeliverablePayload::class))->getConstructor();

    expect($ctor)->not->toBeNull()
        ->and($ctor->isPrivate())->toBeTrue('a public constructor would let callers bypass the DeliverableKind gate');

    expect((new ReflectionClass(DeliverablePayload::class))->isFinal())->toBeTrue(
        'a subclass could add a note-accepting constructor',
    );
});

test('no adapter-namespace method accepts message kinds or message rows — DeliverablePayload is the only content door', function () {
    $namespaceDir = app_path('Domain/Conversations/Adapters');
    $forbidden = [
        MessageKind::class,
        'App\\Domain\\Conversations\\Messages\\Message', // the model, once step 2 creates it
    ];
    // The one sanctioned exception: the conversion point itself, whose JOB is to
    // refuse note/system (asserted above).
    $sanctioned = [DeliverableKind::class.'::fromMessageKind'];
    // Inbound normalization DTOs legitimately carry MessageKind (inbound is customer
    // content; NormalizedInbound's constructor refuses note — asserted above).
    $inboundDtos = [
        'App\\Domain\\Conversations\\Adapters\\NormalizedInbound',
        'App\\Domain\\Conversations\\Adapters\\InboundMedia',
    ];

    $checkedMethods = 0;
    foreach (glob($namespaceDir.'/*.php') as $file) {
        $class = 'App\\Domain\\Conversations\\Adapters\\'.basename($file, '.php');
        expect(class_exists($class) || interface_exists($class) || enum_exists($class))->toBeTrue("$class must be loadable");

        $reflection = new ReflectionClass($class);
        if (in_array($class, $inboundDtos, true)) {
            continue;
        }

        foreach ($reflection->getMethods() as $method) {
            if ($method->getDeclaringClass()->getName() !== $class) {
                continue;
            }
            if (in_array($class.'::'.$method->getName(), $sanctioned, true)) {
                continue;
            }

            foreach ($method->getParameters() as $parameter) {
                $type = $parameter->getType();
                if ($type instanceof ReflectionNamedType) {
                    expect(in_array($type->getName(), $forbidden, true))->toBeFalse(
                        "{$class}::{$method->getName()}() accepts {$type->getName()} — an adapter path notes could ride",
                    );
                }
                $checkedMethods++;
            }
        }
    }

    expect($checkedMethods)->toBeGreaterThan(10, 'the sweep must actually have swept the namespace');
});
