<?php

declare(strict_types=1);

namespace App\Domain\Sms\Timeline;

use App\Domain\Sms\Support\PhoneMask;
use Illuminate\Support\Facades\DB;

/**
 * The Doc 07 timeline's Doc 14 read (P-TIMELINE-SMS's countersign condition):
 * `forMessageIds()` returns EXACTLY `{state, phone_masked, event, lane}` per
 * id — the LIVE state (so US-02's "delivered turns green" reflects a real
 * DLR the moment the row moves), the DRAWN masked phone (`+251 91 ••• 4417`
 * — never the full number, never a body; SEC-02/[D17]), the event and the
 * lane. Nothing else crosses to the timeline. `SmsTimelineFactsShapeTest`
 * asserts the key set is exactly these four.
 */
final class SmsTimelineFacts
{
    /**
     * @param  list<string>  $smsMessageIds
     * @return array<string, array{state: string, phone_masked: string, event: string, lane: string}>
     */
    public function forMessageIds(array $smsMessageIds): array
    {
        if ($smsMessageIds === []) {
            return [];
        }

        $out = [];
        /** @var iterable<object{id: string, state: string, phone: string, event: string, lane: string}> $rows */
        $rows = DB::table('sms_messages')->whereIn('id', $smsMessageIds)->get(['id', 'state', 'phone', 'event', 'lane']);
        foreach ($rows as $row) {
            $out[$row->id] = [
                'state' => $row->state,
                'phone_masked' => PhoneMask::display($row->phone),
                'event' => $row->event,
                'lane' => $row->lane,
            ];
        }

        return $out;
    }
}
