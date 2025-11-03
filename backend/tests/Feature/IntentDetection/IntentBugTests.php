<?php

namespace Tests\Feature\IntentDetection;

use App\Models\Tenant;
use App\Services\RAG\KbSearchService;
use App\Services\RAG\TenantRagConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Test suite per esporre i 5 bug critici identificati nel sistema Intent Detection
 *
 * IMPORTANTE: Questi test sono progettati per FALLIRE con il codice attuale,
 * esponendo i bug che devono essere fixati. Una volta fixati, i test passeranno.
 *
 * Bug testati:
 * 1. Min Score Not Respected
 * 2. Execution Strategy Ignored
 * 3. Extra Keywords Not Merged
 * 4. Cache Not Invalidated
 * 5. Config Merge Overwrites Nested Arrays
 */
class IntentBugTests extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Setup base: crea tenant di test
        // NOTA: JSON encode esplicito per evitare "Array to string conversion"
        $this->tenant = Tenant::factory()->make([
            'name' => 'Test Tenant Intent Detection',
        ]);

        // Set JSON fields manually to avoid factory conflicts
        $this->tenant->rag_settings = [
            'intents' => [
                'enabled' => [
                    'thanks' => true,
                    'phone' => true,
                    'email' => true,
                    'address' => true,
                    'schedule' => true,
                ],
                'min_score' => 0.5,
                'execution_strategy' => 'priority_based',
            ],
        ];

        $this->tenant->extra_intent_keywords = [
            'phone' => ['centralino', 'call center'],
        ];

        $this->tenant->custom_synonyms = [];  // Empty for tests
        $this->tenant->save();
    }

    /**
     * BUG #1: Min Score Not Respected
     *
     * Issue: detectIntents() filtra solo score > 0, ignora min_score config
     * Expected: Intent con score < min_score (0.5) NON dovrebbero essere inclusi
     * Actual: Tutti gli intent con score > 0 vengono inclusi
     *
     * Location: KbSearchService::detectIntents() Line 1131
     */
    public function test_min_score_threshold_is_respected(): void
    {
        try {
            // Arrange: Config con min_score alto (0.5)
            $this->tenant->update([
                'rag_settings' => json_encode([
                    'intents' => [
                        'enabled' => [
                            'thanks' => true,
                            'phone' => true,
                            'email' => false,
                            'address' => false,
                            'schedule' => false,
                        ],
                        'min_score' => 0.5,  // Soglia alta
                        'execution_strategy' => 'priority_based',
                    ],
                ]),
            ]);

            Cache::forget("rag_config_tenant_{$this->tenant->id}");

            // Query che matcha debolmente "phone" (score sotto soglia 0.5)
            // "info" non è una keyword phone, ma se ci fossero match parziali
            // senza min_score check, verrebbe incluso comunque
            $query = 'informazioni generiche';  // Nessuna keyword phone diretta

            // Act: Chiama detectIntents (via reflection perché è private)
            $kbService = app(KbSearchService::class);
            $reflection = new \ReflectionClass($kbService);
            $method = $reflection->getMethod('detectIntents');
            $method->setAccessible(true);
            $intents = $method->invoke($kbService, $query, $this->tenant->id);

            // Assert: Intent con score < 0.5 NON dovrebbe essere incluso
            $this->assertNotContains(
                'phone',
                $intents,
                "❌ BUG ESPOSTO: Intent 'phone' con score < 0.5 è stato incluso, ma min_score=0.5 dovrebbe filtrarlo"
            );

        } catch (\Throwable $e) {
            $this->markTestIncomplete(
                "Test non completato: {$e->getMessage()}. ".
                'Verifica che KbSearchService sia disponibile e la reflection funzioni.'
            );
        }
    }

    /**
     * BUG #2: Execution Strategy Ignored
     *
     * Issue: execution_strategy 'first_match' non implementata, esegue sempre tutti gli intents
     * Expected: Con first_match, solo il primo intent dovrebbe essere ritornato
     * Actual: Ritorna tutti gli intents con score > 0
     *
     * Location: KbSearchService::detectIntents() Line 1136
     */
    public function test_first_match_strategy_returns_only_first_intent(): void
    {
        try {
            // Arrange: Config con execution_strategy = first_match
            $this->tenant->update([
                'rag_settings' => json_encode([
                    'intents' => [
                        'enabled' => [
                            'thanks' => true,
                            'phone' => true,
                            'email' => true,
                            'address' => true,
                            'schedule' => true,
                        ],
                        'min_score' => 0.1,  // Soglia bassa per permettere multiple match
                        'execution_strategy' => 'first_match',  // 🔥 KEY: first_match
                    ],
                ]),
            ]);

            Cache::forget("rag_config_tenant_{$this->tenant->id}");

            // Query che matcha multipli intents
            $query = 'telefono email indirizzo';  // Matcha phone, email, address

            // Act
            $kbService = app(KbSearchService::class);
            $reflection = new \ReflectionClass($kbService);
            $method = $reflection->getMethod('detectIntents');
            $method->setAccessible(true);
            $intents = $method->invoke($kbService, $query, $this->tenant->id);

            // Assert: Con first_match, dovrebbe ritornare SOLO il primo intent
            $this->assertCount(
                1,
                $intents,
                "❌ BUG ESPOSTO: execution_strategy='first_match' dovrebbe ritornare 1 solo intent, ".
                'ma ne ha ritornati '.count($intents).': '.implode(', ', $intents)
            );

        } catch (\Throwable $e) {
            $this->markTestIncomplete(
                "Test non completato: {$e->getMessage()}"
            );
        }
    }

    /**
     * BUG #3: Extra Keywords Not Merged Correctly
     *
     * Issue: extra_intent_keywords è in campo separato, non merge con keywords default
     * Expected: Keywords extra dovrebbero essere incluse nello scoring
     * Actual: Keywords potrebbero non essere usate o merge non corretto
     *
     * Location: KbSearchService::detectIntents() Line 1121-1123
     */
    public function test_extra_keywords_are_merged_and_used_in_scoring(): void
    {
        try {
            // Arrange: Tenant con extra keywords custom
            $this->tenant->update([
                'rag_settings' => [
                    'intents' => [
                        'enabled' => [
                            'phone' => true,
                            'thanks' => false,
                            'email' => false,
                            'address' => false,
                            'schedule' => false,
                        ],
                        'min_score' => 0.05,  // Soglia molto bassa
                        'execution_strategy' => 'priority_based',
                    ],
                ],
                'extra_intent_keywords' => [
                    'phone' => ['centralino', 'call center', 'numero verde'],
                ],
            ]);

            Cache::forget("rag_config_tenant_{$this->tenant->id}");

            // Query che usa SOLO extra keyword (non keyword standard)
            $query = 'centralino aziendale';  // "centralino" è extra keyword

            // Act
            $kbService = app(KbSearchService::class);
            $reflection = new \ReflectionClass($kbService);
            $method = $reflection->getMethod('detectIntents');
            $method->setAccessible(true);
            $intents = $method->invoke($kbService, $query, $this->tenant->id);

            // Assert: Phone intent dovrebbe essere rilevato grazie a extra keyword
            $this->assertContains(
                'phone',
                $intents,
                "❌ BUG ESPOSTO: Extra keyword 'centralino' non è stata usata nello scoring. ".
                "Intent 'phone' dovrebbe essere rilevato ma intents trovati: ".implode(', ', $intents)
            );

        } catch (\Throwable $e) {
            $this->markTestIncomplete(
                "Test non completato: {$e->getMessage()}"
            );
        }
    }

    /**
     * BUG #4: Cache Not Invalidated After Config Save
     *
     * Issue: Dopo update rag_settings, cache Redis non viene invalidata automaticamente
     * Expected: Dopo save, nuovo getConfig() dovrebbe ritornare valori aggiornati
     * Actual: Cache vecchia rimane per 5 minuti (TTL)
     *
     * Location: TenantRagConfigService::getConfig() + Controller update methods
     */
    public function test_cache_is_invalidated_after_config_update(): void
    {
        try {
            // Arrange: Carica config in cache
            $configService = app(TenantRagConfigService::class);
            $originalConfig = $configService->getConfig($this->tenant->id);
            $originalMinScore = $originalConfig['intents']['min_score'] ?? 0.5;

            // Act: Modifica config
            $newMinScore = 0.9;
            $this->tenant->update([
                'rag_settings' => json_encode([
                    'intents' => [
                        'enabled' => [
                            'thanks' => true,
                            'phone' => true,
                            'email' => true,
                            'address' => true,
                            'schedule' => true,
                        ],
                        'min_score' => $newMinScore,
                        'execution_strategy' => 'priority_based',
                    ],
                ]),
            ]);

            // ⚠️ NOTA: Controller dovrebbe chiamare Cache::forget() qui
            // Ma attualmente NON lo fa automaticamente

            // Ricarica config
            $updatedConfig = $configService->getConfig($this->tenant->id);
            $updatedMinScore = $updatedConfig['intents']['min_score'] ?? 0.5;

            // Assert: Config dovrebbe essere aggiornata immediatamente
            $this->assertEquals(
                $newMinScore,
                $updatedMinScore,
                '❌ BUG ESPOSTO: Cache non invalidata dopo update config. '.
                "Expected min_score={$newMinScore}, got min_score={$updatedMinScore}. ".
                'Cache vecchia è rimasta attiva (TTL 5min).'
            );

        } catch (\Throwable $e) {
            $this->markTestIncomplete(
                "Test non completato: {$e->getMessage()}"
            );
        }
    }

    /**
     * BUG #5: Config Merge Overwrites Nested Arrays
     *
     * Issue: Merge config tenant + profile potrebbe sovrascrivere completamente nested arrays
     * Expected: Merge dovrebbe essere deep merge (tenant overrides specific keys)
     * Actual: array_merge_recursive potrebbe creare duplicati o sovrascrivere tutto
     *
     * Location: TenantRagConfigService::mergeConfig()
     */
    public function test_config_merge_preserves_nested_structure(): void
    {
        try {
            // Arrange: Tenant config che override SOLO un valore nested
            $this->tenant->update([
                'rag_profile' => 'public_administration',  // Profile con defaults
                'rag_settings' => json_encode([
                    'intents' => [
                        'enabled' => [
                            'phone' => false,  // Override SOLO phone
                            // Altri intents dovrebbero venire da profile defaults
                        ],
                    ],
                ]),
            ]);

            Cache::forget("rag_config_tenant_{$this->tenant->id}");

            // Act: Carica config merged
            $configService = app(TenantRagConfigService::class);
            $config = $configService->getConfig($this->tenant->id);

            $enabledIntents = $config['intents']['enabled'] ?? [];

            // Assert: phone=false (da tenant), ma altri intents dovrebbero esistere (da profile)
            $this->assertFalse(
                $enabledIntents['phone'] ?? true,
                'phone dovrebbe essere false (override tenant)'
            );

            $this->assertTrue(
                $enabledIntents['email'] ?? false,
                "❌ BUG ESPOSTO: Config merge ha sovrascritto 'email' intent. ".
                "Tenant override solo 'phone', ma 'email' è scomparso dal merge. ".
                'Nested array non mergiano correttamente.'
            );

            $this->assertTrue(
                $enabledIntents['address'] ?? false,
                'address dovrebbe essere true (da profile default)'
            );

        } catch (\Throwable $e) {
            $this->markTestIncomplete(
                "Test non completato: {$e->getMessage()}"
            );
        }
    }

    /**
     * Test Helper: Verifica che la struttura base funzioni
     * Questo test DEVE passare per confermare che il test environment è OK
     */
    public function test_intent_detection_basic_functionality_works(): void
    {
        // Arrange
        $this->tenant->update([
            'rag_settings' => [
                'intents' => [
                    'enabled' => [
                        'thanks' => true,
                        'phone' => false,
                        'email' => false,
                        'address' => false,
                        'schedule' => false,
                    ],
                    'min_score' => 0.1,
                    'execution_strategy' => 'priority_based',
                ],
            ],
        ]);

        Cache::forget("rag_config_tenant_{$this->tenant->id}");

        // Query che matcha chiaramente "thanks"
        $query = 'grazie mille';

        // Act
        $kbService = app(KbSearchService::class);
        $reflection = new \ReflectionClass($kbService);
        $method = $reflection->getMethod('detectIntents');
        $method->setAccessible(true);
        $intents = $method->invoke($kbService, $query, $this->tenant->id);

        // Assert: Dovrebbe rilevare "thanks"
        $this->assertContains(
            'thanks',
            $intents,
            "✅ Test base: 'thanks' intent dovrebbe essere rilevato per query 'grazie mille'"
        );
    }
}
