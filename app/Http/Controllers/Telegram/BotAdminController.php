<?php

declare(strict_types=1);

namespace App\Http\Controllers\Telegram;

use App\Domain\Telegram\Bots\BotProvisioner;
use App\Models\TelegramBot;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * FR-01/FR-03/FR-04's server side (the B6-S01 wizard screens land at step
 * 10; these endpoints are their contract). Permission: telegram.bots.manage
 * (registry line 484); audit rides the route middleware, actor + old/new in
 * the middleware's payload capture.
 *
 * SEC-01: the token is accepted on CREATE and never returned by any
 * response — TelegramBot's $hidden holds; changing a token is re-entry
 * (a fresh store call), never a read-modify-write.
 */
final class BotAdminController
{
    public function store(Request $request, BotProvisioner $provisioner): JsonResponse
    {
        /** @var array{bot_token: string, mode: string} $input */
        $input = $request->validate([
            'bot_token' => ['required', 'string', 'regex:/^\d{6,12}:[A-Za-z0-9_-]{30,}$/'],
            'mode' => ['required', Rule::in(['direct', 'business', 'hybrid'])],
        ]);

        try {
            $result = $provisioner->register($input['bot_token'], $input['mode']);
        } catch (DomainException $e) {
            return new JsonResponse(['code' => 'telegram.bot.rejected', 'detail' => $e->getMessage()], 422);
        }

        return new JsonResponse([
            'bot' => $result['bot'],
            'webhook_verified' => $result['verified'],
        ], 201);
    }

    public function reRegister(string $botId, BotProvisioner $provisioner): JsonResponse
    {
        $bot = TelegramBot::query()->findOrFail($botId);
        $result = $provisioner->reRegister($bot);

        return new JsonResponse(['webhook_verified' => $result['verified']]);
    }

    public function destroy(Request $request, string $botId, BotProvisioner $provisioner): JsonResponse
    {
        $bot = TelegramBot::query()->findOrFail($botId);

        /** @var array{cascade_acknowledged?: bool} $input */
        $input = $request->validate([
            'cascade_acknowledged' => ['sometimes', 'boolean'],
        ]);

        try {
            $provisioner->archive($bot, (bool) ($input['cascade_acknowledged'] ?? false));
        } catch (DomainException $e) {
            return new JsonResponse(['code' => 'telegram.bot.connections_active', 'detail' => $e->getMessage()], 422);
        }

        return new JsonResponse(['archived' => true]);
    }
}
