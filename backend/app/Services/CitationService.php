<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Document;
use App\Services\SettingService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Gestisce la normalizzazione delle citazioni RAG prima dell'esposizione verso il widget/API.
 *
 * - Applica un limite massimo configurabile di fonti.
 * - Recupera i titoli aggiornati dei documenti dal database (scoping per tenant).
 * - Garantisce che ogni citazione esponga campi coerenti (`source_id`, `page_url`, `document_title`).
 */
class CitationService
{
    public function __construct(
        private readonly SettingService $settingService
    ) {}

    /**
     * Normalizza e limita le citazioni da restituire al widget/API.
     *
     * @param  array<int, array<string, mixed>>  $citations
     * @return Collection<int, array<string, mixed>>
     */
    public function getCitations(array $citations, int $tenantId, ?int $maxSources = null): Collection
    {
        if ($maxSources !== null && $maxSources <= 0) {
            throw new InvalidArgumentException('Max sources must be greater than zero.');
        }

        $limit = $maxSources ?? $this->settingService->getMaxCitationSources($tenantId);
        $citations = array_values($citations);

        if ($limit < count($citations)) {
            $citations = array_slice($citations, 0, $limit);
        }

        $documentIds = collect($citations)
            ->map(fn (array $citation) => $citation['document_id'] ?? $citation['id'] ?? null)
            ->filter()
            ->unique();

        $documentTitles = collect();

        if ($documentIds->isNotEmpty()) {
            try {
                $documentTitles = Document::query()
                    ->where('tenant_id', $tenantId)
                    ->whereIn('id', $documentIds)
                    ->pluck('title', 'id');
            } catch (Throwable $exception) {
                Log::error('citations.document_lookup_failed', [
                    'tenant_id' => $tenantId,
                    'document_ids' => $documentIds->values()->all(),
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return collect($citations)->map(function (array $citation) use ($documentTitles) {
            $documentId = $citation['document_id'] ?? $citation['id'] ?? null;
            $documentTitle = $documentTitles[$documentId] ?? ($citation['title'] ?? $citation['document_title'] ?? null);
            $pageUrl = $citation['document_source_url'] ?? $citation['url'] ?? null;

            $normalized = $citation;
            $normalized['source_id'] = $documentId;

            if ($documentTitle !== null) {
                $normalized['document_title'] = $documentTitle;
                $normalized['title'] = $documentTitle;
            }

            if ($pageUrl !== null) {
                $normalized['page_url'] = $pageUrl;
                $normalized['url'] = $pageUrl;
            }

            return $normalized;
        });
    }
}


