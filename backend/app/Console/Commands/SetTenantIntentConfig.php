<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Command;

class SetTenantIntentConfig extends Command
{
    protected $signature = 'tenant:intents:set {--tenant=} {--enable=} {--extra=} {--mode=relaxed} {--score=}';
    protected $description = 'Imposta configurazioni intent per un tenant (abilitazioni, keywords extra, scoping KB, soglia)';

    public function handle(): int
    {
        $tenantId = (int) $this->option('tenant');
        if ($tenantId <= 0) {
            $this->error('Specifica --tenant=ID');
            return self::FAILURE;
        }
        $tenant = Tenant::find($tenantId);
        if (!$tenant) {
            $this->error('Tenant non trovato');
            return self::FAILURE;
        }

        // Abilitazioni intent
        $enable = $this->option('enable');
        $intentsEnabled = $tenant->intents_enabled ?? [];
        if ($enable !== null) {
            $list = array_filter(array_map('trim', explode(',', (string) $enable)));
            $keys = ['phone','email','address','schedule'];
            foreach ($keys as $k) {
                $intentsEnabled[$k] = in_array($k, $list, true);
            }
        }

        // Extra keywords
        $extra = $this->option('extra');
        $extraParsed = $tenant->extra_intent_keywords ?? [];
        if ($extra !== null) {
            $decoded = json_decode((string) $extra, true);
            if (!is_array($decoded)) {
                $this->error('Parametro --extra deve essere JSON valido');
                return self::FAILURE;
            }
            $extraParsed = $decoded;
        }

        // Scoping KB mode
        $mode = (string) $this->option('mode');
        if (!in_array($mode, ['relaxed','strict'], true)) {
            $this->error('Valore --mode non valido. Usa relaxed|strict');
            return self::FAILURE;
        }

        // Soglia opzionale
        $score = $this->option('score');
        $scoreVal = $score !== null ? (float) $score : null;

        $tenant->update([
            'intents_enabled' => $intentsEnabled ?: null,
            'extra_intent_keywords' => $extraParsed ?: null,
            'kb_scope_mode' => $mode,
            'intent_min_score' => $scoreVal,
        ]);

        $this->info('Impostazioni intent aggiornate per tenant '.$tenant->id);
        return self::SUCCESS;
    }
}


