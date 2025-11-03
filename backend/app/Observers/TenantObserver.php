<?php

namespace App\Observers;

use App\Models\Tenant;
use App\Services\RAG\TenantRagConfigService;

class TenantObserver
{
    public function __construct(private readonly TenantRagConfigService $configService) {}

    /**
     * ✅ FIX BUG 4: Invalida cache RAG config quando tenant viene aggiornato
     *
     * Campi che influenzano RAG config:
     * - rag_settings (config nidificata)
     * - rag_profile (determina defaults da applicare)
     * - extra_intent_keywords (keyword custom per intent detection)
     * - custom_synonyms (espansione query)
     */
    public function updated(Tenant $tenant): void
    {
        // Check se sono stati modificati campi che influenzano la RAG config
        $ragRelatedFields = [
            'rag_settings',
            'rag_profile',
            'extra_intent_keywords',
            'custom_synonyms',
        ];

        $shouldClearCache = false;
        foreach ($ragRelatedFields as $field) {
            if ($tenant->isDirty($field)) {
                $shouldClearCache = true;
                break;
            }
        }

        if ($shouldClearCache) {
            $this->configService->clearCache($tenant->id);

            \Log::debug('TenantObserver: Cache RAG config invalidata', [
                'tenant_id' => $tenant->id,
                'modified_fields' => array_keys($tenant->getDirty()),
            ]);
        }
    }

    /**
     * Invalida cache anche quando tenant viene eliminato
     */
    public function deleted(Tenant $tenant): void
    {
        $this->configService->clearCache($tenant->id);
    }
}
