<?php

declare(strict_types=1);

namespace App\Http\Controllers\Channels;

use App\Domain\Conversations\Adapters\AdapterRegistry;
use App\Jobs\Conversations\NormalizeInbound;
use App\Support\Quota\Quota;
use App\Support\Quota\QuotaMeter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * FR-10 step 1: authenticate, enqueue raw, ACK ≤ 250 ms — NEVER process inline.
 *
 * Everything interesting is deliberately absent. Normalization, dedupe, identity,
 * persistence all live in NormalizeInbound on the channel's inbound family queue;
 * this method's whole budget is one quota check and one queue push, which is how
 * NFR-01's 250 ms ACK stays honest under load (the channel is retrying against
 * THIS response time, not against our pipeline).
 *
 * AUTH IS THE MIDDLEWARE STACK'S, NOT THIS CLASS'S. Doc 02 built the webhook
 * surface contract — tenant binder (key → RLS) → per-key throttle → second
 * credential (webhook.auth) → tenant.state:inbound — and each real channel mounts
 * this controller on that stack with its own key type: Doc 05 replaces the
 * telegram probe's handler, Doc 13 the widget's (each also deleting its
 * audit-exempt line, the Doc 01 handoff). No fake-channel production route exists
 * this session — ResolutionSource is a closed enum and the fake adapter is a code
 * fixture, not a channel — so Doc 04's tests mount this controller on the same
 * middleware contract themselves.
 *
 * tenant.state:inbound on that stack is what keeps BR-05 honest: a SUSPENDED
 * tenant's webhook still resolves, still ACKs, still enqueues — the accrual
 * decision (store vs cold-summarize) belongs to the pipeline, never to the edge.
 *
 * An unregistered channel 404s exactly like an unresolved tenant (C-10: refusals
 * are indistinguishable, probing gets nothing), with a security metric.
 */
final class ChannelWebhookController
{
    public function __construct(
        private readonly AdapterRegistry $registry,
        private readonly QuotaMeter $quota,
    ) {}

    public function ingest(Request $request, string $channel, string $endpoint): JsonResponse
    {
        if (! in_array($channel, $this->registry->channels(), true)) {
            Log::info('webhook for unregistered channel refused', [
                'security_metric' => 'messaging.webhook.unknown_channel',
                'channel' => $channel,
            ]);

            throw new NotFoundHttpException;
        }

        // SEC-05 (Doc 01): the quota check runs BEFORE the expensive work — and
        // everything downstream of this line is the expensive work.
        $this->quota->allow(Quota::MessagesPerMinute);

        /** @var array<string, mixed> $raw */
        $raw = $request->json()->all();

        NormalizeInbound::dispatch($channel, $endpoint, $raw)
            ->onQueue($this->registry->inboundFamily($channel));

        return new JsonResponse(['accepted' => true], 202);
    }
}
