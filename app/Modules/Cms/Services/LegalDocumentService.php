<?php

namespace App\Modules\Cms\Services;

use App\Modules\Audit\AuditLogger;
use App\Modules\Cms\Models\LegalDocument;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Documentele juridice sunt versionate: o versiune publicată nu se modifică (acceptările clienților
 * fac referire la versiune și la hash). Modificările se fac într-o versiune nouă, apoi se publică.
 */
class LegalDocumentService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function newVersion(LegalDocument $source, string $version, ?int $userId): LegalDocument
    {
        if (LegalDocument::query()->where('type', $source->type)->where('version', $version)->exists()) {
            throw new RuntimeException("Versiunea {$version} există deja pentru acest document.");
        }
        $copy = $source->replicate(['status', 'effective_at', 'content_hash', 'published_by']);
        $copy->forceFill(['version' => $version, 'status' => 'draft', 'effective_at' => null, 'content_hash' => null, 'published_by' => null])->save();
        $this->audit->log('legal.version_created', $copy, ['from' => $source->version]);

        return $copy;
    }

    public function publish(LegalDocument $document, ?CarbonImmutable $effectiveAt, int $userId): LegalDocument
    {
        if ($document->status !== 'draft') {
            throw new RuntimeException('Doar o versiune în lucru poate fi publicată.');
        }

        return DB::transaction(function () use ($document, $effectiveAt, $userId) {
            $document->forceFill([
                'status' => 'published',
                'effective_at' => $effectiveAt ?? now(),
                'content_hash' => $document->computeHash(),
                'published_by' => $userId,
            ])->save();
            if (($effectiveAt ?? now())->lessThanOrEqualTo(now())) {
                LegalDocument::query()->where('type', $document->type)->whereKeyNot($document->id)->where('status', 'published')->update(['status' => 'superseded']);
            }
            $this->audit->log('legal.published', $document, ['version' => $document->version, 'open_review_flags' => $document->hasOpenReviewFlags()]);

            return $document;
        });
    }
}
