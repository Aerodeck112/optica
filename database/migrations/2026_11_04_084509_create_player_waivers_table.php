<?php

use App\Modules\Cms\Models\LegalDocument;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Declarația de informare, acceptare a riscurilor și acord de participare (documentul proprietarului, v1.0 / 02.11.2026):
 * textul versionat (RO + EN), declarațiile semnate online sau la recepție, permisiunea de acces și linkul din e-mailuri.
 * Datele personale din declarații (data nașterii, telefon, e-mail, semnătură) sunt criptate în baza de date.
 */
return new class extends Migration
{
    private const LINK = [
        'ro' => ['before' => '[Gestionează rezervarea]', 'text' => "Înainte de vizită, fiecare participant (pentru minori, părintele) completează declarația de participare: [Completează declarația]({{ booking.waiver_url }})\n\n"],
        'en' => ['before' => '[Manage your booking]', 'text' => "Before your visit, every player (a parent, for children) completes the participation agreement: [Complete the agreement]({{ booking.waiver_url }})\n\n"],
    ];

    public function up(): void
    {
        Schema::create('player_waivers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('legal_document_id')->nullable()->constrained('legal_documents')->nullOnDelete();
            $table->string('document_version', 20);
            $table->string('document_hash', 64);
            $table->string('locale', 5)->default('ro');
            $table->string('participant_name', 120);
            $table->text('participant_birth_date');            // criptat
            $table->boolean('is_minor')->default(false);
            $table->string('guardian_name', 120)->nullable();
            $table->string('guardian_relationship', 60)->nullable();
            $table->text('guardian_phone')->nullable();          // criptat
            $table->string('signer_name', 120);
            $table->string('signer_role', 10);                   // self | guardian
            $table->text('email')->nullable();                   // criptat
            $table->longText('signature');                       // imagine PNG (data URL), criptată
            $table->boolean('marketing_email')->default(false);
            $table->boolean('photo_consent')->default(false);
            $table->string('source', 10)->default('online');     // online | reception
            $table->string('ip_hash', 64)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('signed_at');
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['booking_id', 'signed_at']);
            $table->index('signed_at');
        });

        $data = json_decode((string) file_get_contents(resource_path('data/player-waiver-1.0.json')), true);
        $document = LegalDocument::query()->firstOrNew(['type' => 'player_waiver', 'version' => $data['version']]);
        if (! $document->exists) {
            $document->fill([
                'title' => $data['title'],
                'slug' => $data['slug'],
                'body' => $data['body'],
                'status' => 'published',
                'effective_at' => now(),
                'review_flags' => array_map(fn ($text) => ['text' => $text, 'resolved' => false], $data['flags']),
            ]);
            $document->content_hash = $document->computeHash();
            $document->save();
        }

        (new RolesAndPermissionsSeeder)->run();

        foreach (['booking.confirmed', 'booking.pending', 'booking.reminder_long'] as $key) {
            foreach (self::LINK as $locale => $link) {
                $template = DB::table('notification_templates')->where(['key' => $key, 'channel' => 'email', 'locale' => $locale])->first();
                if ($template && ! str_contains($template->body, 'booking.waiver_url') && str_contains($template->body, $link['before'])) {
                    DB::table('notification_templates')->where('id', $template->id)
                        ->update(['body' => str_replace($link['before'], $link['text'].$link['before'], $template->body), 'updated_at' => now()]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('player_waivers');
    }
};
