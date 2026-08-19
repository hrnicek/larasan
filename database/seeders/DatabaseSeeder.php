<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        /*
         * The seeders below create accounts with a known password. Running them against a
         * production database would hand anyone who reads this file a way in, so the
         * refusal is here rather than in a comment asking people not to.
         */
        if (app()->isProduction()) {
            throw new RuntimeException('Seeding is refused in production: these seeders create accounts with a known password.');
        }

        $this->call(DevelopmentSeeder::class);
    }
}
