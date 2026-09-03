<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Nothing to seed.
 *
 * GeoScan has no accounts and no reference data: every row in the database is
 * the product of a scan, a search or a host visit, i.e. of a real scraping run.
 * Seeding fake ones would defeat the point of an archive.
 */
class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        //
    }
}
