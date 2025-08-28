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

        $validated = $validator->validated();
        
        // Build RAG settings from form data
        $ragSettings = [
            'hybrid' => [
                'vector_top_k' => (int) $validated['vector_top_k'],
                'bm25_top_k' => (int) $validated['bm25_top_k'],
                'rrf_k' => (int) $validated['rrf_k'],
                'mmr_lambda' => (float) $validated['mmr_lambda'],
                'mmr_take' => (int) $validated['mmr_take'],
                'neighbor_radius' => (int) $validated['neighbor_radius'],
            ],
            'multiquery' => [
                'enabled' => $request->boolean('multiquery_enabled'),
                'num' => (int) $validated['multiquery_num'],
                'temperature' => (float) $validated['multiquery_temperature'],
            ],
            'answer' => [
                'min_citations' => (int) $validated['min_citations'],
                'min_confidence' => (float) $validated['min_confidence'],
                'force_if_has_citations' => $request->boolean('force_if_has_citations'),
                'fallback_message' => $validated['fallback_message'] ?? '',
            ],
            'reranker' => [
                'driver' => $validated['reranker_driver'],
                'top_n' => (int) $validated['reranker_top_n'],
            ],
            'context' => [
                'max_chars' => (int) $validated['context_max_chars'],
                'compress_if_over_chars' => (int) $validated['compress_if_over_chars'],
                'compress_target_chars' => (int) $validated['compress_target_chars'],
            ],
            'advanced' => [
                'hyde' => [
                    'enabled' => $request->boolean('hyde_enabled'),
                    'weight_original' => (float) $validated['hyde_weight_original'],
                    'weight_hypothetical' => (float) $validated['hyde_weight_hypothetical'],
                ],
                'llm_reranker' => [
                    'enabled' => $request->boolean('llm_reranker_enabled'),
                    'batch_size' => (int) $validated['llm_reranker_batch_size'],
                ],
            ],
            'intents' => [
                'enabled' => [
                    'thanks' => $request->boolean('intent_thanks'),
                    'phone' => $request->boolean('intent_phone'),
                    'email' => $request->boolean('intent_email'),
                    'address' => $request->boolean('intent_address'),
                    'schedule' => $request->boolean('intent_schedule'),
                ],
                'min_score' => (float) $validated['intent_min_score'],
                'execution_strategy' => $validated['intent_execution_strategy'],
            ],
            'kb_selection' => [
                'mode' => $validated['kb_selection_mode'],
                'bm25_boost_factor' => (float) $validated['bm25_boost_factor'],
                'vector_boost_factor' => (float) $validated['vector_boost_factor'],
            ],
        ];

        // Update tenant profile and settings
        $tenant->rag_profile = $validated['rag_profile'] !== 'custom' ? $validated['rag_profile'] : null;
        $tenant->rag_settings = $ragSettings;
        $tenant->save();

        // Clear config cache
        $this->configService->updateTenantConfig($tenant->id, $ragSettings);

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
            'mmr_take' => 'required|integer|min:1|max:50',
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
            'context_max_chars' => 'required|integer|min:1000|max:20000',
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
        ], [
            'vector_top_k.max' => 'Vector Top K non può essere superiore a 200',
            'mmr_lambda.between' => 'MMR Lambda deve essere tra 0 e 1',
            'min_confidence.between' => 'Min Confidence deve essere tra 0 e 1',
            'reranker_driver.in' => 'Driver reranker non valido',
        ]);
    }
}
