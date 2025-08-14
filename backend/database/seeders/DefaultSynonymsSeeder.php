<?php

namespace Database\Seeders;

use App\Models\Tenant;
use Illuminate\Database\Seeder;

class DefaultSynonymsSeeder extends Seeder
{
    /**
     * Imposta i sinonimi di default per tutti i tenant esistenti e futuri.
     */
    public function run(): void
    {
        $defaultSynonyms = $this->getDefaultSynonyms();
        
        // Aggiorna tutti i tenant esistenti che non hanno sinonimi personalizzati
        $tenants = Tenant::whereNull('custom_synonyms')->get();
        
        foreach ($tenants as $tenant) {
            $tenant->custom_synonyms = $defaultSynonyms;
            $tenant->save();
            
            $this->command->info("Sinonimi impostati per tenant: {$tenant->name} (ID: {$tenant->id})");
        }
        
        $this->command->info('Configurazione sinonimi completata per ' . $tenants->count() . ' tenant(s)');
        $this->command->info('I nuovi tenant erediteranno automaticamente questi sinonimi dal factory/seeder');
    }
    
    /**
     * Restituisce la mappa dei sinonimi di default
     */
    public static function getDefaultSynonyms(): array
    {
        return [
            // Forze dell'ordine e servizi pubblici
            'vigili urbani' => 'polizia locale municipale vigili',
            'polizia locale' => 'vigili urbani municipale',
            'polizia municipale' => 'vigili urbani polizia locale',
            'vigili' => 'polizia locale vigili urbani',
            'municipio' => 'comune ufficio municipale',
            'comune' => 'municipio ufficio comunale',
            'anagrafe' => 'ufficio anagrafico comune municipio',
            'ufficio tecnico' => 'comune municipio tecnico',
            
            // Servizi sanitari
            'pronto soccorso' => 'ospedale emergenza 118',
            'ospedale' => 'pronto soccorso sanitario',
            'asl' => 'azienda sanitaria ospedale',
            'guardia medica' => 'servizio sanitario medico emergenza',
            
            // Servizi postali e telecomunicazioni
            'poste' => 'ufficio postale poste italiane',
            'ufficio postale' => 'poste poste italiane',
            
            // Trasporti e mobilità
            'stazione' => 'fermata trasporti treno autobus',
            'fermata' => 'stazione trasporti fermata autobus',
            'parcheggio' => 'area sosta auto parcheggio',
            'ztl' => 'zona traffico limitato centro storico',
            
            // Servizi educativi
            'scuola' => 'istituto scolastico educativo scuola',
            'università' => 'ateneo università istituto superiore',
            'biblioteca' => 'biblioteca comunale lettura libri',
            
            // Servizi commerciali e negozi
            'farmacia' => 'farmacia comunale guardia medica',
            'supermercato' => 'negozio alimentari spesa market',
            'centro commerciale' => 'galleria negozi shopping center',
            
            // Geografia e luoghi
            'centro storico' => 'centro città centro urbano',
            'periferia' => 'zone esterne quartieri residenziali',
            'frazione' => 'borgata località paese frazione',
            
            // Servizi finanziari
            'banca' => 'istituto credito bancario filiale',
            'bancomat' => 'sportello automatico prelievo atm',
            'ufficio postale' => 'poste servizi postali spedizioni',
            
            // Eventi e cultura
            'teatro' => 'auditorium sala spettacoli teatro',
            'museo' => 'museo galleria esposizione cultura',
            'parco' => 'giardini area verde parco pubblico',
        ];
    }
}