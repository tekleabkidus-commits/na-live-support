<?php

declare(strict_types=1);

namespace App\Http\Controllers\Widget;

use App\Http\Controllers\Controller;
use Symfony\Component\HttpFoundation\Response;

/**
 * The catch-all OPTIONS handler for the widget surface (Doc 02 T-06, AC-06).
 *
 * Exists so cross-origin preflights have a route to land on: a preflight
 * carries neither the widget key nor a body, so it can never pass the tenant
 * binder the real routes live behind. WidgetCors — this route's ONLY
 * middleware — intercepts and answers every genuine preflight before this
 * method runs; what reaches here is a bare OPTIONS with no
 * Access-Control-Request-Method, which gets an empty 204 and learns nothing.
 */
final class WidgetPreflightController extends Controller
{
    public function __invoke(): Response
    {
        return new Response('', 204);
    }
}
