<?php

declare(strict_types=1);

use Symfony\Component\HttpFoundation\Cookie;

/**
 * FR-10's named gap from the S18 audit: the session cookie's flags were config
 * with no executed assertion — only the widget cookie was flag-asserted. This
 * suite closes it the same way WidgetSessionTest does: by reading the REAL
 * Set-Cookie header off a real response, not by reading config back to itself.
 */
it('issues the staff session cookie HttpOnly, Secure and SameSite=Lax (Doc02 FR-10)', function (): void {
    $response = test()
        ->withServerVariables(['REMOTE_ADDR' => '203.0.113.'.random_int(2, 250)])
        ->get('https://localhost/login');

    $response->assertOk();

    $sessionCookieName = config('session.cookie');
    expect($sessionCookieName)->toBeString()->not->toBe('');

    $cookie = collect($response->baseResponse->headers->getCookies())
        ->first(fn (Cookie $cookie): bool => $cookie->getName() === $sessionCookieName);

    // Positive control first: no cookie found means the assertion below would
    // be vacuous, and a renamed cookie must fail HERE with a clear message.
    expect($cookie)->not->toBeNull(
        sprintf('No "%s" session cookie was issued on the login page response.', $sessionCookieName),
    );

    /** @var Cookie $cookie */
    expect($cookie->isHttpOnly())->toBeTrue('FR-10: the session cookie must be HttpOnly.');
    expect($cookie->isSecure())->toBeTrue('FR-10: the session cookie must be Secure.');
    expect($cookie->getSameSite())->toBe('lax', 'FR-10: the session cookie must be SameSite=Lax.');
});

it('POSITIVE CONTROL: the flag assertions read the live config→cookie chain, not defaults', function (): void {
    // Flip all three flags and require each to come out the other end. If any
    // of the three assertions above could pass with the chain severed, this
    // test is the one that goes red.
    config()->set('session.http_only', false);
    config()->set('session.secure', false);
    config()->set('session.same_site', 'strict');

    $response = test()
        ->withServerVariables(['REMOTE_ADDR' => '203.0.113.'.random_int(2, 250)])
        ->get('https://localhost/login');

    $sessionCookieName = config('session.cookie');

    $cookie = collect($response->baseResponse->headers->getCookies())
        ->first(fn (Cookie $cookie): bool => $cookie->getName() === $sessionCookieName);

    expect($cookie)->not->toBeNull();

    /** @var Cookie $cookie */
    expect($cookie->isHttpOnly())->toBeFalse();
    expect($cookie->isSecure())->toBeFalse();
    expect($cookie->getSameSite())->toBe('strict');
});
