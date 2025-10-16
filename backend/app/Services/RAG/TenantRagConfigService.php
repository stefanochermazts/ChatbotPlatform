<?php

namespace App\Services\RAG;

use App\Models\Tenant;
use Illuminate\Support\Facades\Cache;

class TenantRagConfigService
{
    private const CACHE_TTL = 300; // 5 minuti

    /**
     * Ottiene la configurazione RAG per un tenant specifico
     * Ordine di priorità:
     * 1. Tenant-specific settings (tenants.rag_settings)
     * 2. Tenant profile defaults (config/rag-tenant-defaults.php)  
     * 3. Global config (config/rag.php)
     */
    public function getConfig(int $tenantId): array
    {
        return Cache::remember("rag_config_tenant_{$tenantId}", self::CACHE_TTL, function () use ($tenantId) {
            $tenant = Tenant::find($tenantId);
            
            if (!$tenant) {
                return $this->getGlobalConfig();
            }

            // Start with global config as base
            $config = $this->getGlobalConfig();
            
            // Apply tenant defaults based on profile
            $tenantDefaults = $this->getTenantDefaults();
            $profile = $tenant->rag_profile ?? null;
            
            if ($profile && isset($tenantDefaults['profiles'][$profile])) {
                $config = $this->mergeConfig($config, $tenantDefaults);
                $config = $this->applyProfileOverrides($config, $tenantDefaults['profiles'][$profile]);
            } else {
                $config = $this->mergeConfig($config, $tenantDefaults);
            }
            
            // Apply tenant-specific overrides
            if ($tenant->rag_settings) {
                $tenantSettings = is_string($tenant->rag_settings) 
                    ? json_decode($tenant->rag_settings, true) 
                    : $tenant->rag_settings;
                    
                if (is_array($tenantSettings)) {
                    $config = $this->mergeConfig($config, $tenantSettings);
                }
            }

            return $config;
        });
    }

    /**
     * Ottiene parametri specifici per sezione
     */
    public function getHybridConfig(int $tenantId): array
    {
        return $this->getConfig($tenantId)['hybrid'] ?? [];
    }

    public function getMultiQueryConfig(int $tenantId): array
    {
        return $this->getConfig($tenantId)['multiquery'] ?? [];
    }

    public function getAnswerConfig(int $tenantId): array
    {
        return $this->getConfig($tenantId)['answer'] ?? [];
    }

    public function getRerankerConfig(int $tenantId): array
    {
        return $this->getConfig($tenantId)['reranker'] ?? [];
    }

    public function getAdvancedConfig(int $tenantId): array
    {
        return $this->getConfig($tenantId)['advanced'] ?? [];
    }

    public function getIntentsConfig(int $tenantId): array
    {
        return $this->getConfig($tenantId)['intents'] ?? [];
    }
    
    public function getKbSelectionConfig(int $tenantId): array
    {
        return $this->getConfig($tenantId)['kb_selection'] ?? [];
    }
    
    public function getWidgetConfig(int $tenantId): array
    {
        return $this->getConfig($tenantId)['widget'] ?? [];
    }
    
    /**
     * Ottiene parametri di chunking per tenant
     * 
     * Returns tenant-specific chunking configuration with fallback to global config.
     * Supports 3-level hierarchy: tenant-specific → profile → global defaults.
     * 
     * @param int $tenantId The tenant ID to retrieve chunking config for
     * @return array{max_chars: int, overlap_chars: int} Chunking parameters
     * 
     * @example
     * // For tenant with custom config
     * $config = $service->getChunkingConfig(5);
     * // Returns: ['max_chars' => 3000, 'overlap_chars' => 300]
     * 
     * // For tenant without custom config
     * $config = $service->getChunkingConfig(1);
     * // Returns: ['max_chars' => 2200, 'overlap_chars' => 250] (from global config)
     */
    public function getChunkingConfig(int $tenantId): array
    {
        $config = $this->getConfig($tenantId);
        
        // ✅ FIXED: Use flattened config keys (Step 1 fix)
        // Priority: tenant.chunking → global rag.chunk_max_chars → hardcoded default
        return [
            'max_chars' => $config['chunking']['max_chars'] ?? config('rag.chunk_max_chars', 2200),
            'overlap_chars' => $config['chunking']['overlap_chars'] ?? config('rag.chunk_overlap_chars', 250),
        ];
    }
    
    /**
     * Ottiene parametri di retrieval ibrido per tenant
     * 
     * Returns tenant-specific retrieval configuration with fallback to global config.
     * Supports 3-level hierarchy: tenant-specific → profile → global defaults.
     * This is the SINGLE SOURCE OF TRUTH for all RAG retrieval parameters.
     * 
     * @param int $tenantId The tenant ID to retrieve config for
     * @return array{
     *   vector_top_k: int,
     *   bm25_top_k: int,
     *   mmr_take: int,
     *   mmr_lambda: float,
     *   rrf_k: int,
     *   neighbor_radius: int
     * } Validated retrieval parameters
     * 
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException If tenant does not exist
     * 
     * @example
     * // For tenant with custom config (e.g., rag_settings.hybrid.vector_top_k = 200)
     * $config = $service->getRetrievalConfig(5);
     * // Returns: ['vector_top_k' => 200, 'bm25_top_k' => 50, ...]
     * 
     * // For tenant without custom config
     * $config = $service->getRetrievalConfig(1);
     * // Returns: ['vector_top_k' => 100, 'bm25_top_k' => 30, ...] (from global config)
     */
    public function getRetrievalConfig(int $tenantId): array
    {
        $config = $this->getConfig($tenantId);
        $hybrid = $config['hybrid'] ?? [];
        
        // Load global defaults as fallback
        $globalHybrid = config('rag.hybrid', []);
        
        // Merge with type casting and validation
        $retrievalConfig = [
            'vector_top_k'    => (int) ($hybrid['vector_top_k'] ?? $globalHybrid['vector_top_k'] ?? 100),
            'bm25_top_k'      => (int) ($hybrid['bm25_top_k'] ?? $globalHybrid['bm25_top_k'] ?? 30),
            'mmr_take'        => (int) ($hybrid['mmr_take'] ?? $globalHybrid['mmr_take'] ?? 50),
            'mmr_lambda'      => (float) ($hybrid['mmr_lambda'] ?? $globalHybrid['mmr_lambda'] ?? 0.1),
            'rrf_k'           => (int) ($hybrid['rrf_k'] ?? $globalHybrid['rrf_k'] ?? 60),
            'neighbor_radius' => (int) ($hybrid['neighbor_radius'] ?? $globalHybrid['neighbor_radius'] ?? 5),
        ];
        
        // Validate ranges
        $retrievalConfig = $this->validateRetrievalConfig($retrievalConfig);
        
        return $retrievalConfig;
    }
    
    /**
     * Valida e sanitizza i parametri di retrieval
     * 
     * @param array $config Raw retrieval config
     * @return array Validated and sanitized config
     */
    private function validateRetrievalConfig(array $config): array
    {
        // Clamp vector_top_k between 1 and 1000
        $config['vector_top_k'] = max(1, min(1000, $config['vector_top_k']));
        
        // Clamp bm25_top_k between 1 and 1000
        $config['bm25_top_k'] = max(1, min(1000, $config['bm25_top_k']));
        
        // Clamp mmr_take between 1 and 200
        $config['mmr_take'] = max(1, min(200, $config['mmr_take']));
        
        // Clamp mmr_lambda between 0.0 and 1.0
        $config['mmr_lambda'] = max(0.0, min(1.0, $config['mmr_lambda']));
        
        // Clamp rrf_k between 1 and 100
        $config['rrf_k'] = max(1, min(100, $config['rrf_k']));
        
        // Clamp neighbor_radius between 0 and 20
        $config['neighbor_radius'] = max(0, min(20, $config['neighbor_radius']));
        
        return $config;
    }

    /**
     * Aggiorna la configurazione RAG per un tenant
     */
    public function updateTenantConfig(int $tenantId, array $settings): bool
    {
        $tenant = Tenant::find($tenantId);
        if (!$tenant) {
            return false;
        }

        $tenant->rag_settings = $settings;
        $saved = $tenant->save();

        if ($saved) {
            Cache::forget("rag_config_tenant_{$tenantId}");
        }

        return $saved;
    }

    /**
     * Resetta la configurazione tenant ai defaults
     */
    public function resetTenantConfig(int $tenantId): bool
    {
        $tenant = Tenant::find($tenantId);
        if (!$tenant) {
            return false;
        }

        $tenant->rag_settings = null;
        $saved = $tenant->save();

        if ($saved) {
            Cache::forget("rag_config_tenant_{$tenantId}");
        }

        return $saved;
    }

    /**
     * Ottiene la configurazione globale da config/rag.php
     */
    private function getGlobalConfig(): array
    {
        return config('rag', []);
    }

    /**
     * Ottiene i defaults per tenant da config/rag-tenant-defaults.php
     */
    private function getTenantDefaults(): array
    {
        return config('rag-tenant-defaults', []);
    }

    /**
     * Merge ricorsivo di configurazioni con override intelligente
     */
    private function mergeConfig(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key])) {
                $base[$key] = $this->mergeConfig($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }
        
        return $base;
    }

    /**
     * Applica override di profilo usando dot notation
     */
    private function applyProfileOverrides(array $config, array $overrides): array
    {
        foreach ($overrides as $key => $value) {
            $this->setConfigValue($config, $key, $value);
        }
        
        return $config;
    }

    /**
     * Imposta valore usando dot notation (es: "hybrid.vector_top_k")
     */
    private function setConfigValue(array &$config, string $key, $value): void
    {
        $keys = explode('.', $key);
        $current = &$config;
        
        foreach ($keys as $k) {
            if (!isset($current[$k])) {
                $current[$k] = [];
            }
            $current = &$current[$k];
        }
        
        $current = $value;
    }

    /**
     * Ottiene template di configurazione per nuovo tenant
     */
    public function getConfigTemplate(string $profile = null): array
    {
        $defaults = $this->getTenantDefaults();
        
        if ($profile && isset($defaults['profiles'][$profile])) {
            return $this->applyProfileOverrides($defaults, $defaults['profiles'][$profile]);
        }
        
        return $defaults;
    }

    /**
     * Valida la configurazione tenant
     */
    public function validateConfig(array $config): array
    {
        $errors = [];
        
        // Validazione hybrid settings
        if (isset($config['hybrid'])) {
            $hybrid = $config['hybrid'];
            
            if (isset($hybrid['vector_top_k']) && ($hybrid['vector_top_k'] < 1 || $hybrid['vector_top_k'] > 200)) {
                $errors[] = 'vector_top_k deve essere tra 1 e 200';
            }
            
            if (isset($hybrid['mmr_lambda']) && ($hybrid['mmr_lambda'] < 0 || $hybrid['mmr_lambda'] > 1)) {
                $errors[] = 'mmr_lambda deve essere tra 0 e 1';
            }
        }
        
        // Validazione answer settings
        if (isset($config['answer'])) {
            $answer = $config['answer'];
            
            if (isset($answer['min_confidence']) && ($answer['min_confidence'] < 0 || $answer['min_confidence'] > 1)) {
                $errors[] = 'min_confidence deve essere tra 0 e 1';
            }
        }
        
        return $errors;
    }
}
