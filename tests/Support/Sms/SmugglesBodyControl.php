<?php

declare(strict_types=1);

namespace Tests\Support\Sms;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The POSITIVE CONTROL for SmsNoFreeTextTest (Doc 14 BR-01): a request shaped
 * exactly like the thing that must never exist — a text-carrying SMS door.
 * It lives under tests/Support so it is never a real route or a real class
 * under App\. The sweep must flag `$body`, `$text` and `rules[msg]`.
 */
final class SmugglesBodyControl extends FormRequest
{
    public string $text = '';

    public function __construct(public string $body = '') {}

    /** @return array<string, string> */
    public function rules(): array
    {
        return ['msg' => 'required|string', 'template_ref' => 'required|string'];
    }
}
