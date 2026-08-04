<?php

declare(strict_types=1);

use App\Domain\Conversations\Adapters\AdapterRegistry;
use App\Domain\Conversations\Adapters\Capabilities;
use App\Domain\Conversations\Adapters\ReplyWindow;
use Tests\Support\Adapters\FakeChannelAdapter;

/*
| AdapterRegistry (Doc 04 FR-09, T-02): channels are registered data — adapter plus
| queue families — looked up by name only at the edges.
*/

function adapterRegistryFixtureAdapter(): FakeChannelAdapter
{
    return new FakeChannelAdapter(new Capabilities(
        maxText: 256,
        mediaTypes: ['photo'],
        supportsTyping: true,
        supportsEdit: true,
        supportsDelete: true,
        supportsButtons: false,
        replyWindow: ReplyWindow::none(),
    ));
}

test('a registered channel resolves its adapter and queue families', function () {
    $registry = new AdapterRegistry;
    $adapter = adapterRegistryFixtureAdapter();

    $registry->register('fakechan', $adapter, 'webchat-in', 'webchat-out');

    expect($registry->adapter('fakechan'))->toBe($adapter)
        ->and($registry->inboundFamily('fakechan'))->toBe('webchat-in')
        ->and($registry->outboundFamily('fakechan'))->toBe('webchat-out')
        ->and($registry->channels())->toBe(['fakechan']);
});

test('an unregistered channel refuses by name', function () {
    expect(fn () => (new AdapterRegistry)->adapter('nochan'))->toThrow(DomainException::class);
});

test('double registration refuses — one channel, one adapter', function () {
    $registry = new AdapterRegistry;
    $registry->register('fakechan', adapterRegistryFixtureAdapter(), 'webchat-in', 'webchat-out');

    expect(fn () => $registry->register('fakechan', adapterRegistryFixtureAdapter(), 'webchat-in', 'webchat-out'))
        ->toThrow(DomainException::class);
});
