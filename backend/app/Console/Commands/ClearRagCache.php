<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

class ClearRagCache extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'rag:clear-cache 
                            {--tenant= : Pulisce solo la cache del tenant specificato}
                            {--pattern= : Pattern specifico da pulire (default: rag:*)}
                            {--dry-run : Mostra cosa verrebbe cancellato senza cancellare}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Pulisce la cache Redis del sistema RAG';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $tenant = $this->option('tenant');
        $pattern = $this->option('pattern') ?: 'rag:*';
        $isDryRun = $this->option('dry-run');

        $this->info('🧹 PULIZIA CACHE RAG');
        $this->newLine();

        // Se specificato un tenant, modifica il pattern per essere tenant-specifico
        if ($tenant) {
            $pattern = "rag:*:{$tenant}:*";
            $this->info("🎯 Tenant specificato: {$tenant}");
        }

        $this->info("🔍 Pattern di ricerca: {$pattern}");
        $this->info('🏃 Modalità: '.($isDryRun ? 'DRY RUN (solo visualizzazione)' : 'ELIMINAZIONE EFFETTIVA'));
        $this->newLine();

        try {
            // Ottieni tutte le chiavi che corrispondono al pattern
            $keys = $this->findCacheKeys($pattern);

            if (empty($keys)) {
                $this->warn("❌ Nessuna chiave trovata con pattern: {$pattern}");

                return 0;
            }

            $this->info('📋 Trovate '.count($keys).' chiavi cache:');

            // Raggruppa le chiavi per tipo per una visualizzazione migliore
            $groupedKeys = $this->groupKeysByType($keys);

            foreach ($groupedKeys as $type => $typeKeys) {
                $this->line("  📂 {$type}: ".count($typeKeys).' chiavi');

                if ($this->option('verbose')) {
                    foreach ($typeKeys as $key) {
                        $this->line("    - {$key}");
                    }
                }
            }

            $this->newLine();

            if ($isDryRun) {
                $this->warn('🏃 DRY RUN: Nessuna chiave è stata effettivamente cancellata');

                return 0;
            }

            // Conferma prima di cancellare (solo se non è dry-run)
            if (! $this->confirm('⚠️  Sei sicuro di voler cancellare tutte queste '.count($keys).' chiavi cache?')) {
                $this->info('❌ Operazione annullata');

                return 0;
            }

            // Cancella le chiavi
            $deleted = $this->deleteCacheKeys($keys);

            $this->info('✅ Cache RAG pulita con successo!');
            $this->line("🗑️  Chiavi cancellate: {$deleted}");

            // Verifica che le chiavi siano state effettivamente cancellate
            $remainingKeys = $this->findCacheKeys($pattern);
            if (count($remainingKeys) > 0) {
                $this->warn('⚠️  Alcune chiavi potrebbero non essere state cancellate: '.count($remainingKeys));
            } else {
                $this->info('✅ Tutte le chiavi sono state cancellate correttamente');
            }

        } catch (\Exception $e) {
            $this->error('❌ Errore durante la pulizia cache: '.$e->getMessage());

            return 1;
        }

        return 0;
    }

    /**
     * Trova tutte le chiavi cache che corrispondono al pattern
     */
    private function findCacheKeys(string $pattern): array
    {
        try {
            // Ottieni le chiavi da Redis usando SCAN per evitare di bloccare Redis
            $redis = Redis::connection();
            $keys = [];

            // Aggiungi il prefisso di Laravel se configurato
            $prefix = config('cache.prefix', '');
            $searchPattern = $prefix ? $prefix.$pattern : $pattern;

            // Usa SCAN invece di KEYS per prestazioni migliori
            $cursor = 0;
            do {
                $result = $redis->scan($cursor, ['match' => $searchPattern, 'count' => 100]);

                // Verifica che il risultato sia un array
                if (! is_array($result) || count($result) < 2) {
                    break;
                }

                $cursor = (int) $result[0];
                $foundKeys = $result[1] ?? [];

                if (is_array($foundKeys)) {
                    foreach ($foundKeys as $key) {
                        $keys[] = $key;
                    }
                }
            } while ($cursor !== 0);

            return $keys;

        } catch (\Exception $e) {
            // Fallback: usa Cache facade per trovare le chiavi
            $this->warn('⚠️  SCAN fallito, uso fallback method: '.$e->getMessage());

            return $this->findCacheKeysFallback($pattern);
        }
    }

    /**
     * Metodo fallback per trovare le chiavi cache
     */
    private function findCacheKeysFallback(string $pattern): array
    {
        try {
            $redis = Redis::connection();
            $prefix = config('cache.prefix', '');
            $searchPattern = $prefix ? $prefix.$pattern : $pattern;

            // Usa KEYS come fallback (meno efficiente ma più sicuro)
            $keys = $redis->keys($searchPattern);

            return is_array($keys) ? $keys : [];

        } catch (\Exception $e) {
            $this->error('❌ Impossibile accedere a Redis: '.$e->getMessage());

            return [];
        }
    }

    /**
     * Raggruppa le chiavi per tipo per una visualizzazione migliore
     */
    private function groupKeysByType(array $keys): array
    {
        $grouped = [
            'Vector+Text Search' => [],
            'Reranking' => [],
            'KB Selection' => [],
            'Embeddings' => [],
            'Altri' => [],
        ];

        foreach ($keys as $key) {
            if (str_contains($key, 'rag:vecfts:')) {
                $grouped['Vector+Text Search'][] = $key;
            } elseif (str_contains($key, 'rag:rerank:')) {
                $grouped['Reranking'][] = $key;
            } elseif (str_contains($key, 'rag:kb:') || str_contains($key, 'kb_selection:')) {
                $grouped['KB Selection'][] = $key;
            } elseif (str_contains($key, 'embeddings:') || str_contains($key, 'emb:')) {
                $grouped['Embeddings'][] = $key;
            } else {
                $grouped['Altri'][] = $key;
            }
        }

        // Rimuovi gruppi vuoti
        return array_filter($grouped, fn ($group) => ! empty($group));
    }

    /**
     * Cancella le chiavi cache specificate
     */
    private function deleteCacheKeys(array $keys): int
    {
        if (empty($keys)) {
            return 0;
        }

        // Cancella usando Laravel Cache facade che gestisce automaticamente i prefissi
        $deleted = 0;

        foreach ($keys as $key) {
            try {
                // Rimuovi il prefisso Laravel se presente per usare Cache::forget
                $cleanKey = $this->removeRedisPrefix($key);

                if (Cache::forget($cleanKey)) {
                    $deleted++;
                }
            } catch (\Exception $e) {
                $this->warn("⚠️  Impossibile cancellare la chiave: {$key} - ".$e->getMessage());
            }
        }

        return $deleted;
    }

    /**
     * Rimuove il prefisso Redis/Laravel dalla chiave se presente
     */
    private function removeRedisPrefix(string $key): string
    {
        // Laravel aggiunge automaticamente il prefisso configurato in cache.php
        $prefix = config('cache.prefix', '');

        if ($prefix && str_starts_with($key, $prefix)) {
            return substr($key, strlen($prefix));
        }

        return $key;
    }
}
