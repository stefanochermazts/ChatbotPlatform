<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\Scraper\ContentQualityAnalyzer;
use Illuminate\Support\Facades\Http;

class TestContentQuality extends Command
{
    protected $signature = 'scraper:test-quality 
                            {url : URL da analizzare}
                            {--detailed : Mostra analisi dettagliata}';
    
    protected $description = 'Testa il sistema di analisi qualità contenuto su un URL specifico';

    public function handle()
    {
        $url = $this->argument('url');
        $detailed = $this->option('detailed');
        
        $this->info("🧠 Analizzando qualità contenuto per: {$url}");
        $this->line('');
        
        try {
            // Fetch dell'HTML
            $this->info('📡 Downloading HTML...');
            $response = Http::timeout(30)->get($url);
            
            if (!$response->successful()) {
                $this->error("❌ Errore download: HTTP {$response->status()}");
                return 1;
            }
            
            $html = $response->body();
            $this->info("✅ HTML scaricato: " . number_format(strlen($html)) . " caratteri");
            $this->line('');
            
            // Analisi qualità
            $analyzer = new ContentQualityAnalyzer();
            $analysis = $analyzer->analyzeContent($html, $url);
            
            // Output risultati
            $this->displayResults($analysis, $detailed);
            
            return 0;
            
        } catch (\Exception $e) {
            $this->error("❌ Errore: " . $e->getMessage());
            return 1;
        }
    }
    
    private function displayResults(array $analysis, bool $detailed): void
    {
        $this->info('📊 RISULTATI ANALISI QUALITÀ');
        $this->line('================================');
        
        // Risultati principali
        $this->line("🎯 <fg=yellow>Tipo Contenuto:</fg=yellow> {$analysis['content_type']}");
        $this->line("📂 <fg=yellow>Categoria:</fg=yellow> {$analysis['content_category']}");
        $this->line("⭐ <fg=yellow>Quality Score:</fg=yellow> " . number_format($analysis['quality_score'], 3));
        $this->line("🔧 <fg=yellow>Strategia Estrazione:</fg=yellow> {$analysis['extraction_strategy']}");
        $this->line("⚡ <fg=yellow>Priorità:</fg=yellow> {$analysis['processing_priority']}");
        
        // Scoring dettagliato
        if ($detailed) {
            $this->line('');
            $this->info('📈 METRICHE DETTAGLIATE');
            $this->line('------------------------');
            
            $metrics = [
                'Text Ratio' => $analysis['text_ratio'],
                'Information Density' => $analysis['information_density'], 
                'Semantic Richness' => $analysis['semantic_richness'],
                'Language Quality' => $analysis['language_quality'],
                'Business Relevance' => $analysis['business_relevance']
            ];
            
            foreach ($metrics as $name => $value) {
                $color = $this->getScoreColor($value);
                $bar = $this->getProgressBar($value);
                $this->line("  <fg={$color}>{$name}:</fg={$color}> {$bar} " . number_format($value, 3));
            }
            
            // Caratteristiche strutturali
            $this->line('');
            $this->info('🏗️ CARATTERISTICHE STRUTTURALI');
            $this->line('-------------------------------');
            
            $features = [
                'Tabelle Complesse' => $analysis['has_complex_tables'],
                'Dati Strutturati' => $analysis['has_structured_data'],
                'Form Interattivi' => $analysis['has_forms'],
                'Navigazione' => $analysis['has_navigation'],
                'Contenuto Media' => $analysis['has_media']
            ];
            
            foreach ($features as $name => $value) {
                $icon = $value ? '✅' : '❌';
                $this->line("  {$icon} {$name}");
            }
            
            // Metadata aggiuntivi
            $this->line('');
            $this->info('ℹ️ METADATA AGGIUNTIVI');
            $this->line('----------------------');
            $this->line("  🌍 Lingua: {$analysis['detected_language']}");
            $this->line("  ⏱️ Tempo Analisi: {$analysis['analysis_time_ms']}ms");
            
            if (!empty($analysis['warnings'])) {
                $this->line('');
                $this->warn('⚠️ AVVERTIMENTI:');
                foreach ($analysis['warnings'] as $warning) {
                    $this->line("  • {$warning}");
                }
            }
        }
        
        // Raccomandazioni
        $this->line('');
        $this->info('💡 RACCOMANDAZIONI');
        $this->line('-------------------');
        
        if ($analysis['quality_score'] >= 0.7) {
            $this->line("  ✨ <fg=green>Contenuto di alta qualità - processare con priorità alta</fg=green>");
        } elseif ($analysis['quality_score'] >= 0.3) {
            $this->line("  ⚖️ <fg=yellow>Contenuto di qualità media - processare normalmente</fg=yellow>");
        } else {
            $this->line("  ⚠️ <fg=red>Contenuto di bassa qualità - considerare skip o processing minimo</fg=red>");
        }
        
        if ($analysis['business_relevance'] > 0.7) {
            $this->line("  💼 <fg=green>Alta rilevanza business - importante per knowledge base</fg=green>");
        }
        
        if ($analysis['has_structured_data']) {
            $this->line("  📋 <fg=cyan>Contiene dati strutturati - usare estrazione DOM manuale</fg=cyan>");
        }
        
        if ($analysis['content_type'] === 'navigation_directory') {
            $this->line("  🗂️ <fg=magenta>Pagina di navigazione - considerare come link-only</fg=magenta>");
        }
    }
    
    private function getScoreColor(float $score): string
    {
        if ($score >= 0.7) return 'green';
        if ($score >= 0.4) return 'yellow';
        return 'red';
    }
    
    private function getProgressBar(float $score, int $width = 20): string
    {
        $filled = (int) round($score * $width);
        $empty = $width - $filled;
        
        return '[' . str_repeat('█', $filled) . str_repeat('░', $empty) . ']';
    }
}
