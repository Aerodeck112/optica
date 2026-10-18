<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Production-safe seeders only. Demo data lives in DemoDataSeeder and is never run here (ARC-08).
     */
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            SiteContentSeeder::class,
            CmsContentSeeder::class,
            ClinicalTemplateSeeder::class,
            MessageTemplateSeeder::class,
        ]);
    }
}
