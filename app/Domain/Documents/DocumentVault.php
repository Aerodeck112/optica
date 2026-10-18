<?php

namespace App\Domain\Documents;

use App\Models\Patient;
use App\Models\PatientDocument;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Private patient files (MED-05): stored outside the web root under random names without an
 * extension, type checked from the content (not the name), downloaded only through an
 * authorised controller that logs each access.
 */
class DocumentVault
{
    public const MIMES = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public const MAX_KB = 20480;

    public const DISK = 'local';

    public function store(Patient $patient, UploadedFile $file, string $category, string $title, User $user, ?int $locationId = null, ?int $recordId = null): PatientDocument
    {
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath());
        if (! isset(self::MIMES[$mime])) {
            throw new RuntimeException('Unsupported document type');
        }
        if ($mime === 'application/pdf' && ! str_starts_with((string) file_get_contents($file->getRealPath(), false, null, 0, 5), '%PDF-')) {
            throw new RuntimeException('Invalid PDF');
        }
        $path = 'patients/'.$patient->id.'/'.Str::uuid();
        Storage::disk(self::DISK)->putFileAs(dirname($path), $file, basename($path));

        $document = PatientDocument::create([
            'patient_id' => $patient->id,
            'clinical_record_id' => $recordId,
            'location_id' => $locationId,
            'category' => in_array($category, PatientDocument::CATEGORIES, true) ? $category : 'other',
            'title' => mb_substr(trim($title), 0, 200),
            'path' => $path,
            'original_name' => mb_substr(basename($file->getClientOriginalName()), 0, 255),
            'mime' => $mime,
            'size' => $file->getSize(),
            'sha256' => hash_file('sha256', $file->getRealPath()),
            'uploaded_by' => $user->id,
        ]);
        app(AuditLogger::class)->log('documents.uploaded', $document, ['patient_id' => $patient->id, 'category' => $document->category], $user, $locationId);

        return $document;
    }

    /** A safe download name: the title plus the extension of the verified type. */
    public function downloadName(PatientDocument $document): string
    {
        $base = Str::slug($document->title) ?: 'document';

        return $base.'.'.(self::MIMES[$document->mime] ?? 'bin');
    }

    public function delete(PatientDocument $document, User $user): void
    {
        // The file is kept until the retention rules are validated (SEC-05); the record is hidden.
        $document->forceFill(['deleted_by' => $user->id])->save();
        $document->delete();
        app(AuditLogger::class)->log('documents.deleted', $document, ['patient_id' => $document->patient_id], $user, $document->location_id);
    }
}
