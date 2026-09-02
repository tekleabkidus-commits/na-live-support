<?php

declare(strict_types=1);

namespace App\Domain\Fields\Seeding;

use App\Domain\Fields\DefinitionWriter;
use Illuminate\Support\Facades\DB;

/**
 * P-FIELDS (S12 batch D, countersigned at the batch-C acceptance): the Doc 08
 * FR-16 betting trio — betting_username, player_id, brand — as REAL
 * field_definitions rows (entity=customer, type text; the seam fixture's own
 * shape, condition 2 of that countersign) at provisioning, so a provisioned
 * tenant never reads the [D12] fixture. Idempotent by key (the
 * keys-never-reused unique is the backstop); the fixture's RETIRED
 * loyalty_tier is deliberately NOT seeded — production gains nothing
 * fictional; EC-09's retired rendering is proven on a real deactivated row.
 * Labels are the platform's own en+am strings (lang/{en,am}/customers.php —
 * already native-reviewed in S08), so Doc 08's label_i18n KEY contract holds
 * for the trio.
 */
final class CustomerFieldSeeder
{
    public const TRIO = ['betting_username', 'player_id', 'brand'];

    /** The provisioning system actor (the KnowledgeBaseSeeder precedent: seeded rows carry the system uuid). */
    public const SYSTEM_ACTOR = '00000000-0000-0000-0000-000000000000';

    public function __construct(private readonly DefinitionWriter $writer) {}

    /** @return list<string> the keys seeded THIS run (a redelivery seeds nothing twice) */
    public function seed(): array
    {
        /** @var list<string> $existing */
        $existing = DB::table('field_definitions')->where('entity', 'customer')->pluck('key')->all();
        $seeded = [];
        foreach (self::TRIO as $key) {
            if (in_array($key, $existing, true)) {
                continue;
            }
            $this->writer->create([
                'entity' => 'customer',
                'key' => $key,
                'label' => [
                    'en' => (string) trans('customers.fields.'.$key, [], 'en'),
                    'am' => (string) trans('customers.fields.'.$key, [], 'am'),
                ],
                'type' => 'text',
            ], self::SYSTEM_ACTOR);
            $seeded[] = $key;
        }

        return $seeded;
    }
}
