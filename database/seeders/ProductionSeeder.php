<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Date de bază, sigure pentru producție: roluri, structuri tehnice, biblioteca media, catalogul (neconfirmat),
 * conținutul site-ului, documentele juridice în ciornă și șabloanele de mesaje.
 * Nu creează conturi cu parole și nu completează date comerciale.
 */
class ProductionSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            BaseDataSeeder::class,
            MediaLibrarySeeder::class,
            CatalogSeeder::class,
            CmsSeeder::class,
            LegalDocumentsSeeder::class,
            NotificationTemplatesSeeder::class,
        ]);
    }
}
