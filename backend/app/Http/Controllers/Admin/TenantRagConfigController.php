<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\RAG\TenantRagConfigService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class TenantRagConfigController extends Controller
{
    public function __construct(private readonly TenantRagConfigService $configService)
    {
    }

    /**
     * Show RAG configuration for a tenant
     */
    public function show(Tenant $tenant)
    {
        $currentConfig = $this->configService->getConfig($tenant->id);
        $availableProfiles = ['public_administration', 'ecommerce', 'customer_service'];
        $configTemplate = $this->configService->getConfigTemplate();

        return view('admin.tenants.rag-config', compact(
            'tenant',
            'currentConfig', 
            'availableProfiles',
            'configTemplate'
        ));
    }

    /**
     * Update RAG configuration for a tenant
     */
    public function update(Request $request, Tenant $tenant)
    {
        $validator = $this->validateConfig($request);
        
        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput();
        }

        // Costruisci struttura NIDIFICATA coerente con config/rag.php
        $settings = [
            'hybrid' => [
                'vector_top_k' => (int) $request->input('vector_top_k'),
                'bm25_top_k' => (int) $request->input('bm25_top_k'),
                'rrf_k' => (int) $request->input('rrf_k'),
                'mmr_lambda' => (float) $request->input('mmr_lambda'),
                'mmr_take' => (int) $request->input('mmr_take'),
                'neighbor_radius' => (int) $request->input('neighbor_radius'),
            ],
            'multiquery' => [
                'enabled' => (bool) $request->boolean('multiquery_enabled'),
                'num' => (int) $request->input('multiquery_num'),
                'temperature' => (float) $request->input('multiquery_temperature'),
            ],
            'answer' => [
                'min_citations' => (int) $request->input('min_citations'),
                'min_confidence' => (float) $request->input('min_confidence'),
                'force_if_has_citations' => (bool) $request->boolean('force_if_has_citations'),
                'fallback_message' => (string) ($request->input('fallback_message') ?? ''),
            ],
            'reranker' => [
                'driver' => (string) $request->input('reranker_driver'),
                'top_n' => (int) $request->input('reranker_top_n'),
            ],
            'context' => [
                'max_chars' => (int) $request->input('context_max_chars'),
                'compress_if_over_chars' => (int) $request->input('compress_if_over_chars'),
                'compress_target_chars' => (int) $request->input('compress_target_chars'),
            ],
            'advanced' => [
                'hyde' => [
                    'enabled' => (bool) $request->boolean('hyde_enabled'),
                    'weight_original' => (float) $request->input('hyde_weight_original'),
                    'weight_hypothetical' => (float) $request->input('hyde_weight_hypothetical'),
                ],
                'llm_reranker' => [
                    'enabled' => (bool) $request->boolean('llm_reranker_enabled'),
                    'batch_size' => (int) $request->input('llm_reranker_batch_size'),
                ],
            ],
            'intents' => [
                'enabled' => [
                    'thanks' => (bool) $request->boolean('intent_thanks'),
                    'phone' => (bool) $request->boolean('intent_phone'),
                    'email' => (bool) $request->boolean('intent_email'),
                    'address' => (bool) $request->boolean('intent_address'),
                    'schedule' => (bool) $request->boolean('intent_schedule'),
                ],
                'min_score' => (float) $request->input('intent_min_score'),
                'execution_strategy' => (string) $request->input('intent_execution_strategy'),
            ],
            'kb_selection' => [
                'mode' => (string) $request->input('kb_selection_mode'),
                'bm25_boost_factor' => (float) $request->input('bm25_boost_factor'),
                'vector_boost_factor' => (float) $request->input('vector_boost_factor'),
            ],
        ];

        // Opzionale: upload_boost può essere nullo
        if ($request->filled('kb_upload_boost')) {
            $settings['kb_selection']['upload_boost'] = (float) $request->input('kb_upload_boost');
        }

        // Parse JSON maps per KB selection
        if ($request->filled('kb_title_keyword_boosts')) {
            $decoded = json_decode((string) $request->input('kb_title_keyword_boosts'), true);
            if (is_array($decoded)) {
                $settings['kb_selection']['title_keyword_boosts'] = $decoded;
            }
        }
        if ($request->filled('kb_location_boosts')) {
            $decoded = json_decode((string) $request->input('kb_location_boosts'), true);
            if (is_array($decoded)) {
                $settings['kb_selection']['location_boosts'] = $decoded;
            }
        }

        // Salva il profilo selezionato sul tenant
        $tenant->rag_profile = (string) $request->input('rag_profile');
        $tenant->save();

        $this->configService->updateTenantConfig($tenant->id, $settings);
        
        return back()->with('success', 'Configurazione RAG aggiornata con successo!');
    }

    /**
     * Reset RAG configuration to defaults
     */
    public function reset(Tenant $tenant)
    {
        $this->configService->resetTenantConfig($tenant->id);
        
        $tenant->rag_profile = null;
        $tenant->rag_settings = null;
        $tenant->save();

        return back()->with('success', 'Configurazione RAG ripristinata ai valori di default!');
    }

    /**
     * Get RAG config template for a profile (AJAX)
     */
    public function getProfileTemplate(Request $request)
    {
        $profile = $request->get('profile');
        $template = $this->configService->getConfigTemplate($profile !== 'custom' ? $profile : null);
        
        return response()->json([
            'success' => true,
            'template' => $template
        ]);
    }

    /**
     * Test RAG configuration with a sample query (AJAX)
     */
    public function testConfig(Request $request, Tenant $tenant)
    {
        $query = $request->get('query', 'orario vigili urbani');
        
        try {
            $kbService = app(\App\Services\RAG\KbSearchService::class);
            $result = $kbService->retrieve($tenant->id, $query, true);
            
            return response()->json([
                'success' => true,
                'citations' => count($result['citations'] ?? []),
                'confidence' => $result['confidence'] ?? 0,
                'debug' => $result['debug'] ?? null,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Validate RAG configuration form data
     */
    private function validateConfig(Request $request)
    {
        return Validator::make($request->all(), [
            'rag_profile' => 'required|in:custom,public_administration,ecommerce,customer_service',
            
            // Hybrid search
            'vector_top_k' => 'required|integer|min:1|max:200',
            'bm25_top_k' => 'required|integer|min:1|max:300',
            'rrf_k' => 'required|integer|min:10|max:100',
            'mmr_lambda' => 'required|numeric|min:0|max:1',
            'mmr_take' => 'required|integer|min:1|max:100',
            'neighbor_radius' => 'required|integer|min:0|max:10',
            
            // Multi-query
            'multiquery_num' => 'required|integer|min:1|max:10',
            'multiquery_temperature' => 'required|numeric|min:0|max:1',
            
            // Answer thresholds
            'min_citations' => 'required|integer|min:0|max:10',
            'min_confidence' => 'required|numeric|min:0|max:1',
            'fallback_message' => 'nullable|string|max:500',
            
            // Reranker
            'reranker_driver' => 'required|in:embedding,cohere,llm,none',
            'reranker_top_n' => 'required|integer|min:1|max:100',
            
            // Context
            'context_max_chars' => 'required|integer|min:1000|max:100000',
            'compress_if_over_chars' => 'required|integer|min:1000|max:25000',
            'compress_target_chars' => 'required|integer|min:500|max:15000',
            
            // Advanced
            'hyde_weight_original' => 'required|numeric|min:0|max:1',
            'hyde_weight_hypothetical' => 'required|numeric|min:0|max:1',
            'llm_reranker_batch_size' => 'required|integer|min:1|max:20',
            
            // Intents
            'intent_min_score' => 'required|numeric|min:0|max:1',
            'intent_execution_strategy' => 'required|in:priority_based,first_match',
            
            // KB Selection
            'kb_selection_mode' => 'required|in:auto,strict,multi',
            'bm25_boost_factor' => 'required|numeric|min:0.1|max:5',
            'vector_boost_factor' => 'required|numeric|min:0.1|max:5',
            'kb_upload_boost' => 'nullable|numeric|min:0.5|max:3',
            'kb_title_keyword_boosts' => 'nullable|string', // JSON
            'kb_location_boosts' => 'nullable|string',      // JSON
        ], [
            'vector_top_k.max' => 'Vector Top K non può essere superiore a 200',
            'mmr_lambda.between' => 'MMR Lambda deve essere tra 0 e 1',
            'min_confidence.between' => 'Min Confidence deve essere tra 0 e 1',
            'reranker_driver.in' => 'Driver reranker non valido',
        ]);
    }
}
