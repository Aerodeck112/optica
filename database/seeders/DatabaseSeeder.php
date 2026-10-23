<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(ProductionSeeder::class);

        if (! app()->isProduction() && env('AW_SEED_DEMO', false)) {
            $this->call(DemoSeeder::class);
        }
    }
}
