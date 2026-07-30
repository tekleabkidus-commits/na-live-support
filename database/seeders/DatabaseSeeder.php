<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * Deliberately empty.
     *
     * Platform reference data (ref_languages, ref_countries, ref_timezones,
     * ref_currencies) is NOT seeded here: Doc 01 T-10 requires it in
     * platform-versioned MIGRATIONS, so that a deploy carries the data and
     * EC-07's additive-only rule is enforceable by the migration linter rather
     * than by whoever remembers to run a seeder.
     *
     * Tenant data comes from provisioning (Doc 01 FR-06), which is a job, not a
     * seeder. Staff users belong to Doc 02 and roles to Doc 03; the Laravel
     * scaffolding user that used to live here would now violate FR-02
     * (non-null tenant_id) and could not be inserted under RLS anyway.
     */
    public function run(): void
    {
        //
    }
}
