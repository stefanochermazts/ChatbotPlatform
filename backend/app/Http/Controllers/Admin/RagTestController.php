<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\LLM\OpenAIChatService;
use App\Services\RAG\MilvusClient;
use App\Services\RAG\KbSearchService;
use Illuminate\Http\Request;

class RagTestController extends Controller
{
    public function index()
    {
        $tenants = Tenant::orderBy('name')->get();
        return view('admin.rag.index', ['tenants' => $tenants, 'result' => null]);
    }

    public function run(Request $request, KbSearchService $kb, OpenAIChatService $chat, MilvusClient $milvus)
    {
        $data = $request->validate([
            'tenant_id' => ['required', 'integer', 'exists:tenants,id'],
            'query' => ['required', 'string'],
            'with_answer' => ['nullable', 'boolean'],
            'top_k' => ['nullable', 'integer', 'min:1', 'max:50'],
            'mmr_lambda' => ['nullable', 'numeric', 'min:0', 'max:1'],
        ]);
        $tenantId = (int) $data['tenant_id'];
        $tenant = Tenant::find($tenantId);
        $health = $milvus->health();
        $retrieval = $kb->retrieve($tenantId, $data['query'], true);
        $citations = $retrieval['citations'] ?? [];
        $confidence = (float) ($retrieval['confidence'] ?? 0.0);
        $trace = $retrieval['debug'] ?? null;
        $answer = null;
        if ((bool) ($data['with_answer'] ?? false)) {
            $contextText = '';
            if (!empty($citations)) {
                $contextParts = [];
                foreach ($citations as $c) {
                    $title = $c['title'] ?? ('Doc '.$c['id']);
                    $snippet = trim((string) ($c['snippet'] ?? ''));
                    $extra = '';
                    if (!empty($c['phone'])) {
                        $extra = "\nTelefono: ".$c['phone'];
                    }
                    if (!empty($c['email'])) {
                        $extra .= "\nEmail: ".$c['email'];
                    }
                    if (!empty($c['address'])) {
                        $extra .= "\nIndirizzo: ".$c['address'];
                    }
                    if (!empty($c['schedule'])) {
                        $extra .= "\nOrario: ".$c['schedule'];
                    }
                    if ($snippet !== '') {
                        $contextParts[] = "[".$title."]\n".$snippet.$extra;
                    } elseif ($extra !== '') {
                        $contextParts[] = "[".$title."]\n".$extra;
                    }
                }
                if ($contextParts !== []) {
                    $rawContext = implode("\n\n---\n\n", $contextParts);
                    // Usa il template personalizzato del tenant se disponibile
                    if ($tenant && !empty($tenant->custom_context_template)) {
                        $contextText = "\n\n" . str_replace('{context}', $rawContext, $tenant->custom_context_template);
                    } else {
                        $contextText = "\n\nContesto (estratti rilevanti):\n".$rawContext;
                    }
                }
            }
            // Costruisci i messaggi utilizzando i prompt personalizzati del tenant
            $messages = [];
            
            // Aggiungi il prompt di sistema personalizzato se disponibile
            if ($tenant && !empty($tenant->custom_system_prompt)) {
                $messages[] = ['role' => 'system', 'content' => $tenant->custom_system_prompt];
            } else {
                $messages[] = ['role' => 'system', 'content' => 'Seleziona solo informazioni dai passaggi forniti nel contesto. Se non sono sufficienti, rispondi: "Non lo so". Riporta sempre le fonti (titoli) usate.'];
            }
            
            $messages[] = ['role' => 'user', 'content' => "Domanda: ".$data['query']."\n".$contextText];
            
            $payload = [
                'model' => 'gpt-4o-mini',
                'messages' => $messages,
            ];
            $answer = $chat->chatCompletions($payload)['choices'][0]['message']['content'] ?? '';
            if (is_array($trace)) {
                $trace['llm_context'] = $contextText;
                $trace['llm_messages'] = $payload['messages'];
                $trace['tenant_prompts'] = [
                    'custom_system_prompt' => $tenant->custom_system_prompt ?? null,
                    'custom_context_template' => $tenant->custom_context_template ?? null,
                    'using_custom_system' => !empty($tenant->custom_system_prompt),
                    'using_custom_context' => !empty($tenant->custom_context_template),
                ];
            }
        }
        $tenants = Tenant::orderBy('name')->get();
        return view('admin.rag.index', ['tenants' => $tenants, 'result' => compact('citations', 'answer', 'confidence', 'health', 'trace'), 'query' => $data['query'], 'tenant_id' => $tenantId]);
    }
}

