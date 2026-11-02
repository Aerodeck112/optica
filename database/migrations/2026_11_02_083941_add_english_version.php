<?php

use App\Modules\Cms\Models\Faq;
use App\Modules\Cms\Services\EnglishContentImporter;
use Illuminate\Database\Migrations\Migration;

/**
 * Versiunea în engleză a site-ului (cerută de proprietar, 02.11.2026): paginile, pachetele, jocurile,
 * întrebările frecvente, meniurile și e-mailurile către clienți. Documentele juridice rămân în română
 * (traducerea lor juridică se face separat); în engleză, linkurile duc la varianta în română.
 */
return new class extends Migration
{
    public function up(): void
    {
        // vânzarea online a voucherelor: răspunsul la întrebarea frecventă se actualizează și în română
        Faq::query()->where('question->ro', 'Unde cumpăr un voucher cadou?')->get()->each(function (Faq $faq) {
            if (str_starts_with((string) $faq->getTranslation('answer', 'ro', false), 'Scrie-ne prin formularul de contact')) {
                $faq->setTranslation('answer', 'ro', 'Comandă-l online, pe pagina Vouchere cadou, sau scrie-ne prin formularul de contact, pe e-mail ori la telefon. Voucherul este personalizat cu numele destinatarului și un scurt mesaj și îl primești digital, gata de trimis sau de printat.');
                $faq->save();
            }
        });

        app(EnglishContentImporter::class)->import();
    }

    public function down(): void {}
};
