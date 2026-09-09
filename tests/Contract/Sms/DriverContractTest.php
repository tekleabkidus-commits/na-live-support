<?php

declare(strict_types=1);

use App\Domain\Sms\Gateways\Capabilities;
use App\Domain\Sms\Gateways\DriverContract;
use App\Domain\Sms\Gateways\Drivers\DriverRegistry;
use App\Domain\Sms\Gateways\Drivers\HttpGenericDriver;
use App\Domain\Sms\Gateways\Drivers\SmppSidecarDriver;
use App\Domain\Sms\Gateways\GatewayConfig;
use App\Domain\Sms\Gateways\ParsedDlr;
use App\Domain\Sms\Gateways\RenderedMessage;
use App\Domain\Sms\Gateways\SendResult;
use App\Models\SmsGateway;

/*
| Doc 14 T-02 — the driver contract is EXACTLY send(RenderedMessage) /
| parseDlr(payload) / capabilities() (+ the health probe the circuit uses),
| both drivers implement it with no extra public surface a caller could reach
| around the contract (no `raw`, no `request`, no body accessor), RenderedMessage
| is the only inbound shape (no `body`/`html` synonym — BR-01), the registry
| resolves the two registered driver keys and refuses a third.
*/

it('T-02: the contract has exactly four methods and both drivers implement it with no public surface beyond it', function (): void {
    $contract = new ReflectionClass(DriverContract::class);
    $names = array_map(static fn (ReflectionMethod $m): string => $m->getName(), $contract->getMethods());
    sort($names);
    expect($names)->toBe(['capabilities', 'healthy', 'parseDlr', 'send']);

    foreach ([HttpGenericDriver::class, SmppSidecarDriver::class] as $driver) {
        $class = new ReflectionClass($driver);
        expect($class->implementsInterface(DriverContract::class))->toBeTrue()->and($class->isFinal())->toBeTrue();
        $public = array_values(array_filter(
            array_map(static fn (ReflectionMethod $m): string => $m->getName(), $class->getMethods(ReflectionMethod::IS_PUBLIC)),
            static fn (string $name): bool => ! str_starts_with($name, '__'),
        ));
        sort($public);
        $allowed = $driver === HttpGenericDriver::class ? ['capabilities', 'healthy', 'mask', 'parseDlr', 'path', 'send'] : ['capabilities', 'healthy', 'parseDlr', 'send'];
        expect($public)->toBe($allowed, $driver.' exposes only the contract (+ the two pure helpers the tests pin)');
    }

    $send = $contract->getMethod('send');
    expect((string) $send->getParameters()[0]->getType())->toBe(RenderedMessage::class)
        ->and((string) $send->getReturnType())->toBe(SendResult::class)
        ->and((string) $contract->getMethod('parseDlr')->getReturnType())->toBe(ParsedDlr::class)
        ->and((string) $contract->getMethod('capabilities')->getReturnType())->toBe(Capabilities::class);
});

it('BR-01: RenderedMessage carries text under ONE name — no body/html/message synonym, and it is readonly', function (): void {
    $class = new ReflectionClass(RenderedMessage::class);
    $properties = array_map(static fn (ReflectionProperty $p): string => $p->getName(), $class->getProperties());
    sort($properties);
    expect($properties)->toBe(['callbackUrl', 'messageId', 'phoneE164', 'senderId', 'text'])->and($class->isReadOnly())->toBeTrue();
});

it('the registry resolves the two registered driver keys and refuses a third', function (): void {
    $registry = app(DriverRegistry::class);
    $gateway = static fn (string $driver): SmsGateway => (new SmsGateway)->forceFill(['driver' => $driver]);
    expect($registry->for($gateway('http_generic')))->toBeInstanceOf(HttpGenericDriver::class)
        ->and($registry->for($gateway('smpp_sidecar')))->toBeInstanceOf(SmppSidecarDriver::class);
    expect(fn () => $registry->for($gateway('twilio')))->toThrow(LogicException::class);

    $capabilities = (new HttpGenericDriver)->capabilities(GatewayConfig::fromStored(['base_url' => 'http://x', 'send' => ['fields' => ['to' => 'to', 'text' => 'text']], 'capabilities' => ['inbound_stop' => true, 'alphanumeric_sender' => false, 'max_throughput' => 7]]));
    expect($capabilities->inboundStop)->toBeTrue()->and($capabilities->alphanumericSender)->toBeFalse()->and($capabilities->maxThroughput)->toBe(7);
});
