<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Platform reference data (Doc 01 FR-10, T-10, BR-03, EC-07).
 *
 * Seeded HERE, in a migration, not in a seeder: T-10 says "platform-versioned
 * migrations", and that word does real work. A deploy then carries the data,
 * and EC-07's additive-only rule becomes checkable by reading migrations
 * (tests/Contract/ReferenceDataAdditiveOnlyTest.php) instead of trusting that
 * someone ran a seeder in the right order.
 *
 * These four tables are the FR-02 / C-02 exception set together with `tenants`:
 * they are platform-global, carry no tenant_id, and therefore no RLS. Tenants
 * SELECT from them and never write (BR-03).
 *
 * Names are i18n KEYS, never display text (N-03 / C-08). "Amharic" is a string
 * an operator may want to see in Amharic.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ref_languages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 16)->unique();          // BCP-47, e.g. "am"
            $table->string('name_i18n_key', 128);
            $table->string('direction', 3);                 // ltr | rtl (C-15)
            $table->timestampTz('deprecated_at')->nullable(); // EC-07 two-release path
            $table->timestampsTz();
        });

        Schema::create('ref_countries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 2)->unique();            // ISO 3166-1 alpha-2
            $table->string('name_i18n_key', 128);
            $table->string('dial_code', 8);
            $table->timestampTz('deprecated_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('ref_timezones', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 64)->unique();           // IANA, e.g. "Africa/Addis_Ababa"
            $table->string('name_i18n_key', 128);
            $table->timestampTz('deprecated_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('ref_currencies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 3)->unique();            // ISO 4217
            $table->string('name_i18n_key', 128);
            $table->string('symbol', 8);
            $table->unsignedSmallInteger('minor_units');    // ETB = 2
            $table->timestampTz('deprecated_at')->nullable();
            $table->timestampsTz();
        });

        $this->seedLanguages();
        $this->seedCountries();
        $this->seedTimezones();
        $this->seedCurrencies();
    }

    public function down(): void
    {
        Schema::dropIfExists('ref_currencies');
        Schema::dropIfExists('ref_timezones');
        Schema::dropIfExists('ref_countries');
        Schema::dropIfExists('ref_languages');
    }

    /**
     * Launch languages only (Doc 01 §14: English + Amharic).
     *
     * Deliberately NOT the full BCP-47 list: FR-10 says tenants select from
     * seeded reference data, and a tenant selecting Japanese would get a
     * platform with no Japanese strings in it. Languages arrive with their
     * translations, as platform releases (BR-03).
     */
    private function seedLanguages(): void
    {
        $this->insert('ref_languages', [
            ['code' => 'en', 'name_i18n_key' => 'ref.language.en', 'direction' => 'ltr'],
            ['code' => 'am', 'name_i18n_key' => 'ref.language.am', 'direction' => 'ltr'],
        ]);
    }

    /**
     * Ethiopia first (CLAUDE.md market), plus the neighbours an operator's
     * players actually roam into. Additive by release.
     */
    private function seedCountries(): void
    {
        $this->insert('ref_countries', [
            ['code' => 'ET', 'name_i18n_key' => 'ref.country.ET', 'dial_code' => '+251'],
            ['code' => 'KE', 'name_i18n_key' => 'ref.country.KE', 'dial_code' => '+254'],
            ['code' => 'DJ', 'name_i18n_key' => 'ref.country.DJ', 'dial_code' => '+253'],
            ['code' => 'SO', 'name_i18n_key' => 'ref.country.SO', 'dial_code' => '+252'],
            ['code' => 'SS', 'name_i18n_key' => 'ref.country.SS', 'dial_code' => '+211'],
            ['code' => 'ER', 'name_i18n_key' => 'ref.country.ER', 'dial_code' => '+291'],
            ['code' => 'GB', 'name_i18n_key' => 'ref.country.GB', 'dial_code' => '+44'],
            ['code' => 'AE', 'name_i18n_key' => 'ref.country.AE', 'dial_code' => '+971'],
        ]);
    }

    private function seedTimezones(): void
    {
        $this->insert('ref_timezones', [
            ['code' => 'Africa/Addis_Ababa', 'name_i18n_key' => 'ref.timezone.Africa_Addis_Ababa'],
            ['code' => 'Africa/Nairobi', 'name_i18n_key' => 'ref.timezone.Africa_Nairobi'],
            ['code' => 'Africa/Djibouti', 'name_i18n_key' => 'ref.timezone.Africa_Djibouti'],
            ['code' => 'UTC', 'name_i18n_key' => 'ref.timezone.UTC'],
            ['code' => 'Europe/London', 'name_i18n_key' => 'ref.timezone.Europe_London'],
            ['code' => 'Asia/Dubai', 'name_i18n_key' => 'ref.timezone.Asia_Dubai'],
        ]);
    }

    private function seedCurrencies(): void
    {
        $this->insert('ref_currencies', [
            ['code' => 'ETB', 'name_i18n_key' => 'ref.currency.ETB', 'symbol' => 'Br', 'minor_units' => 2],
            ['code' => 'USD', 'name_i18n_key' => 'ref.currency.USD', 'symbol' => '$', 'minor_units' => 2],
            ['code' => 'EUR', 'name_i18n_key' => 'ref.currency.EUR', 'symbol' => '€', 'minor_units' => 2],
            ['code' => 'KES', 'name_i18n_key' => 'ref.currency.KES', 'symbol' => 'KSh', 'minor_units' => 2],
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function insert(string $table, array $rows): void
    {
        $now = now();

        DB::table($table)->insert(array_map(
            fn (array $row): array => $row + [
                'id' => (string) Str::uuid7(),   // C-01
                'created_at' => $now,
                'updated_at' => $now,
            ],
            $rows,
        ));
    }
};
