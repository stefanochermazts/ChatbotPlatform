<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\WidgetEvent;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class TestWidgetAnalytics extends Command
{
    protected $signature = 'widget:test-analytics {tenant_id} {--events=20 : Number of test events to create} {--days=7 : Spread events over N days}';
    protected $description = 'Generate test analytics data for widget dashboard testing';

    public function handle()
    {
        $tenantId = (int) $this->argument('tenant_id');
        $eventsCount = (int) $this->option('events');
        $daysSpread = (int) $this->option('days');

        // Verify tenant exists
        $tenant = Tenant::find($tenantId);
        if (!$tenant) {
            $this->error("Tenant with ID {$tenantId} not found.");
            return Command::FAILURE;
        }

        $this->info("Generating {$eventsCount} test analytics events for tenant: {$tenant->name}");
        $this->info("Events will be spread over {$daysSpread} days");

        // Generate test sessions
        $sessions = $this->generateTestSessions($tenantId, $eventsCount, $daysSpread);

        $this->info("✅ Created " . count($sessions) . " test sessions with analytics events");
        
        // Show summary
        $this->showAnalyticsSummary($tenantId);

        return Command::SUCCESS;
    }

    private function generateTestSessions(int $tenantId, int $totalEvents, int $daysSpread): array
    {
        $sessions = [];
        $eventTypes = [
            'widget_loaded' => 0.15,
            'chatbot_opened' => 0.25, 
            'message_sent' => 0.35,
            'message_received' => 0.20,
            'chatbot_closed' => 0.04,
            'message_error' => 0.01,
        ];

        $userAgents = [
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1',
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:109.0) Gecko/20100101 Firefox/121.0',
        ];

        $sampleQueries = [
            'Ciao, come posso aiutarti?',
            'Quali sono i vostri orari di apertura?',
            'Quanto costa il servizio premium?',
            'Come posso contattare il supporto?',
            'Dove si trova la vostra sede?',
            'Avete sconti per studenti?',
            'Come funziona la garanzia?',
            'Posso modificare il mio abbonamento?',
            'Che metodi di pagamento accettate?',
            'È possibile ottenere un rimborso?',
        ];

        $sampleResponses = [
            'Ciao! Sono qui per aiutarti. Cosa posso fare per te oggi?',
            'I nostri orari di apertura sono dal lunedì al venerdì dalle 9:00 alle 18:00.',
            'Il servizio premium costa €29.99 al mese e include tutte le funzionalità avanzate.',
            'Puoi contattare il nostro supporto via email a support@example.com o chiamando il numero verde.',
            'La nostra sede principale si trova in Via Roma 123, Milano.',
            'Sì, offriamo uno sconto del 50% per studenti universitari con tessera valida.',
            'La garanzia copre tutti i difetti di fabbricazione per 24 mesi dalla data di acquisto.',
            'Puoi modificare il tuo abbonamento in qualsiasi momento dalla tua area personale.',
            'Accettiamo carte di credito, PayPal, bonifico bancario e Apple/Google Pay.',
            'I rimborsi sono possibili entro 14 giorni dall\'acquisto secondo i nostri termini di servizio.',
        ];

        $eventsCreated = 0;
        $bar = $this->output->createProgressBar($totalEvents);
        $bar->start();

        while ($eventsCreated < $totalEvents) {
            // Generate random session
            $sessionId = 'session_' . time() . '_' . Str::random(8);
            $sessionStart = now()->subDays(random_int(0, $daysSpread - 1))
                ->addHours(random_int(8, 22))
                ->addMinutes(random_int(0, 59));

            $userAgent = $userAgents[array_rand($userAgents)];
            $ipAddress = $this->generateRandomIP();

            // Generate 1-5 events per session
            $sessionEvents = random_int(1, 5);
            $currentTime = $sessionStart;

            for ($i = 0; $i < $sessionEvents && $eventsCreated < $totalEvents; $i++) {
                $eventType = $this->selectEventType($eventTypes, $i, $sessionEvents);
                $eventData = $this->generateEventData($eventType, $sampleQueries, $sampleResponses);

                WidgetEvent::create([
                    'tenant_id' => $tenantId,
                    'event_type' => $eventType,
                    'session_id' => $sessionId,
                    'event_timestamp' => $currentTime,
                    'user_agent' => $userAgent,
                    'ip_address' => $ipAddress,
                    'event_data' => $eventData['data'],
                    'response_time_ms' => $eventData['response_time'],
                    'confidence_score' => $eventData['confidence'],
                    'tokens_used' => $eventData['tokens'],
                    'had_error' => $eventData['had_error'],
                    'citations_count' => $eventData['citations'],
                ]);

                $currentTime = $currentTime->addSeconds(random_int(5, 120));
                $eventsCreated++;
                $bar->advance();
            }

            $sessions[] = $sessionId;
        }

        $bar->finish();
        $this->line('');
        
        return $sessions;
    }

    private function selectEventType(array $eventTypes, int $eventIndex, int $totalSessionEvents): string
    {
        // First event is usually widget_loaded or chatbot_opened
        if ($eventIndex === 0) {
            return random_int(0, 1) ? 'widget_loaded' : 'chatbot_opened';
        }

        // Last event is usually chatbot_closed
        if ($eventIndex === $totalSessionEvents - 1) {
            return 'chatbot_closed';
        }

        // Middle events are usually messages
        $messageEvents = ['message_sent', 'message_received'];
        if ($eventIndex % 2 === 1) {
            return 'message_sent';
        } else {
            return random_int(0, 100) < 95 ? 'message_received' : 'message_error';
        }
    }

    private function generateEventData(string $eventType, array $queries, array $responses): array
    {
        $data = [];
        $response_time = null;
        $confidence = null;
        $tokens = null;
        $had_error = false;
        $citations = null;

        switch ($eventType) {
            case 'widget_loaded':
                $data = [
                    'page_url' => 'https://example-client.com/contact',
                    'screen_resolution' => '1920x1080',
                    'viewport_size' => '1200x800',
                    'timezone' => 'Europe/Rome',
                    'language' => 'it-IT',
                ];
                break;

            case 'chatbot_opened':
            case 'chatbot_closed':
                $data = [
                    'timestamp' => now()->toISOString(),
                ];
                break;

            case 'message_sent':
                $data = [
                    'query' => $queries[array_rand($queries)],
                    'query_length' => random_int(10, 100),
                ];
                break;

            case 'message_received':
                $response_time = random_int(500, 3000);
                $confidence = round(random_int(70, 95) / 100, 2);
                $tokens = random_int(50, 300);
                $citations = random_int(0, 3);
                
                $data = [
                    'response' => $responses[array_rand($responses)],
                    'response_length' => random_int(50, 500),
                    'citations' => $citations,
                    'confidence' => $confidence,
                    'tokens_used' => $tokens,
                ];
                break;

            case 'message_error':
                $had_error = true;
                $data = [
                    'error' => 'API timeout after 30 seconds',
                    'context' => 'message_processing',
                    'query' => $queries[array_rand($queries)],
                ];
                break;
        }

        return [
            'data' => $data,
            'response_time' => $response_time,
            'confidence' => $confidence,
            'tokens' => $tokens,
            'had_error' => $had_error,
            'citations' => $citations,
        ];
    }

    private function generateRandomIP(): string
    {
        return implode('.', [
            random_int(1, 255),
            random_int(0, 255),
            random_int(0, 255),
            random_int(1, 255),
        ]);
    }

    private function showAnalyticsSummary(int $tenantId): void
    {
        $this->line('');
        $this->info('📊 Analytics Summary:');
        
        $totalEvents = WidgetEvent::forTenant($tenantId)->count();
        $uniqueSessions = WidgetEvent::forTenant($tenantId)->distinct('session_id')->count();
        $totalMessages = WidgetEvent::forTenant($tenantId)->where('event_type', 'message_sent')->count();
        $totalResponses = WidgetEvent::forTenant($tenantId)->where('event_type', 'message_received')->count();
        $avgResponseTime = WidgetEvent::forTenant($tenantId)
            ->where('event_type', 'message_received')
            ->avg('response_time_ms');
        
        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Events', number_format($totalEvents)],
                ['Unique Sessions', number_format($uniqueSessions)],
                ['Messages Sent', number_format($totalMessages)],
                ['Responses Generated', number_format($totalResponses)],
                ['Avg Response Time', $avgResponseTime ? number_format($avgResponseTime) . 'ms' : 'N/A'],
            ]
        );

        $this->line('');
        $this->info('🔗 View analytics at: /admin/widget-analytics?tenant_id=' . $tenantId);
    }
}