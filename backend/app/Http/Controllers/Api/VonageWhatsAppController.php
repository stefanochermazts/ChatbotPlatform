<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\VonageMessage;
use App\Services\LLM\OpenAIChatService;
use App\Services\RAG\KbSearchService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class VonageWhatsAppController extends Controller
{
    protected $chatService;

    protected $kbService;

    public function __construct(OpenAIChatService $chatService, KbSearchService $kbService)
    {
        $this->chatService = $chatService;
        $this->kbService = $kbService;
    }

    /**
     * Inbound URL - Riceve messaggi WhatsApp da Vonage
     */
    public function inbound(Request $request)
    {
        try {
            $payload = $request->all();

            Log::info('Vonage WhatsApp Inbound Message', ['payload' => $payload]);

            // Estrai dati del messaggio
            $messageId = $payload['message_uuid'] ?? null;
            $from = $payload['from'] ?? null;
            $to = $payload['to'] ?? null;
            $messageType = $payload['message']['content']['type'] ?? 'text';
            $messageText = $payload['message']['content']['text'] ?? '';
            $timestamp = $payload['timestamp'] ?? now();

            // Verifica che sia un messaggio di testo valido
            if (! $from || ! $messageText || $messageType !== 'text') {
                Log::warning('Invalid WhatsApp message received', ['payload' => $payload]);

                return response()->json(['status' => 'ignored'], 200);
            }

            // Trova il tenant basato sul numero WhatsApp (to)
            $tenant = $this->findTenantByWhatsAppNumber($to);
            if (! $tenant) {
                Log::error('No tenant found for WhatsApp number: '.$to);

                return response()->json(['status' => 'no_tenant'], 200);
            }

            // Salva il messaggio ricevuto
            $this->saveMessage($tenant->id, $messageId, $from, $to, $messageText, 'inbound');

            // Processa il messaggio con RAG
            $response = $this->processMessageWithRAG($messageText, $tenant->id);

            // Invia la risposta tramite Vonage
            $this->sendWhatsAppMessage($from, $response, $tenant);

            return response()->json(['status' => 'processed'], 200);

        } catch (\Exception $e) {
            Log::error('Error processing WhatsApp inbound message', [
                'error' => $e->getMessage(),
                'payload' => $request->all(),
            ]);

            return response()->json(['status' => 'error'], 500);
        }
    }

    /**
     * Status URL - Riceve aggiornamenti di stato dei messaggi
     */
    public function status(Request $request)
    {
        try {
            $payload = $request->all();

            Log::info('Vonage WhatsApp Status Update', ['payload' => $payload]);

            $messageId = $payload['message_uuid'] ?? null;
            $status = $payload['status'] ?? null;
            $timestamp = $payload['timestamp'] ?? now();

            if ($messageId && $status) {
                // Aggiorna lo stato del messaggio nel database
                VonageMessage::where('message_id', $messageId)
                    ->update([
                        'status' => $status,
                        'status_updated_at' => $timestamp,
                    ]);
            }

            return response()->json(['status' => 'received'], 200);

        } catch (\Exception $e) {
            Log::error('Error processing WhatsApp status update', [
                'error' => $e->getMessage(),
                'payload' => $request->all(),
            ]);

            return response()->json(['status' => 'error'], 500);
        }
    }

    /**
     * Trova il tenant basato sul numero WhatsApp destinatario
     */
    private function findTenantByWhatsAppNumber($whatsappNumber)
    {
        // Normalizza il numero (rimuovi prefisso +, spazi, etc.)
        $normalizedNumber = $this->normalizePhoneNumber($whatsappNumber);

        // Cerca tenant con questo numero WhatsApp configurato
        $tenant = Tenant::whereNotNull('whatsapp_config')
            ->get()
            ->filter(function ($tenant) use ($normalizedNumber, $whatsappNumber) {
                $config = $tenant->getWhatsAppConfig();
                if (! $config['is_active'] || ! $config['phone_number']) {
                    return false;
                }

                $tenantNumber = $this->normalizePhoneNumber($config['phone_number']);

                return $tenantNumber === $normalizedNumber || $config['phone_number'] === $whatsappNumber;
            })->first();

        if (! $tenant) {
            Log::warning('No tenant found for WhatsApp number', [
                'number' => $whatsappNumber,
                'normalized' => $normalizedNumber,
            ]);
        }

        return $tenant;
    }

    /**
     * Normalizza il numero di telefono per il matching
     */
    private function normalizePhoneNumber($phoneNumber)
    {
        if (! $phoneNumber) {
            return null;
        }

        // Rimuovi tutti i caratteri non numerici eccetto +
        $cleaned = preg_replace('/[^\d+]/', '', $phoneNumber);

        // Se inizia con +, mantienilo, altrimenti rimuovi prefissi comuni
        if (strpos($cleaned, '+') === 0) {
            return $cleaned;
        }

        // Rimuovi 0 iniziale se presente (numero italiano)
        if (strpos($cleaned, '0') === 0) {
            $cleaned = substr($cleaned, 1);
        }

        return $cleaned;
    }

    /**
     * Processa il messaggio con RAG
     */
    private function processMessageWithRAG($message, $tenantId)
    {
        try {
            // Cerca nella knowledge base del tenant
            $searchResults = $this->kbService->search($message, $tenantId);

            // Costruisci il prompt con il contesto
            $context = '';
            if (! empty($searchResults['citations'])) {
                foreach ($searchResults['citations'] as $citation) {
                    $context .= $citation['content']."\n\n";
                }
            }

            $prompt = "Basandoti esclusivamente sul seguente contesto, rispondi alla domanda dell'utente. Se la risposta non è nel contesto, rispondi 'Non ho informazioni sufficienti per rispondere a questa domanda'.\n\nContesto:\n{$context}\n\nDomanda: {$message}\n\nRisposta:";

            // Genera la risposta con OpenAI
            $response = $this->chatService->generateResponse([
                ['role' => 'user', 'content' => $prompt],
            ]);

            return $response;

        } catch (\Exception $e) {
            Log::error('Error processing message with RAG', ['error' => $e->getMessage()]);

            return 'Mi dispiace, al momento non riesco a elaborare la tua richiesta. Riprova più tardi.';
        }
    }

    /**
     * Invia messaggio WhatsApp tramite Vonage
     */
    private function sendWhatsAppMessage($to, $message, $tenant)
    {
        try {
            $vonageApiKey = config('services.vonage.api_key');
            $vonageApiSecret = config('services.vonage.api_secret');
            $whatsappNumber = $tenant->whatsapp_config['phone_number'] ?? config('services.vonage.whatsapp_number');

            $response = Http::withBasicAuth($vonageApiKey, $vonageApiSecret)
                ->post('https://messages-sandbox.nexmo.com/v1/messages', [
                    'from' => $whatsappNumber,
                    'to' => $to,
                    'message_type' => 'text',
                    'text' => $message,
                    'channel' => 'whatsapp',
                ]);

            if ($response->successful()) {
                $responseData = $response->json();
                $messageId = $responseData['message_uuid'] ?? null;

                // Salva il messaggio inviato
                $this->saveMessage($tenant->id, $messageId, $whatsappNumber, $to, $message, 'outbound');

                Log::info('WhatsApp message sent successfully', [
                    'message_id' => $messageId,
                    'to' => $to,
                ]);
            } else {
                Log::error('Failed to send WhatsApp message', [
                    'response' => $response->json(),
                    'status' => $response->status(),
                ]);
            }

        } catch (\Exception $e) {
            Log::error('Error sending WhatsApp message', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Salva il messaggio nel database
     */
    private function saveMessage($tenantId, $messageId, $from, $to, $text, $direction)
    {
        VonageMessage::create([
            'tenant_id' => $tenantId,
            'message_id' => $messageId,
            'from' => $from,
            'to' => $to,
            'message' => $text,
            'direction' => $direction,
            'channel' => 'whatsapp',
            'status' => 'sent',
            'created_at' => now(),
        ]);
    }
}
