<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\LLM\OpenAIChatService;
use App\Services\RAG\KbSearchService;
use Illuminate\Http\Request;

class RagTestController extends Controller
{
    public function index()
    {
        $tenants = Tenant::orderBy('name')->get();
        return view('admin.rag.index', ['tenants' => $tenants, 'result' => null]);
    }

    public function run(Request $request, KbSearchService $kb, OpenAIChatService $chat)
    {
        $data = $request->validate([
            'tenant_id' => ['required', 'integer', 'exists:tenants,id'],
            'query' => ['required', 'string'],
            'with_answer' => ['nullable', 'boolean'],
            'top_k' => ['nullable', 'integer', 'min:1', 'max:50'],
            'mmr_lambda' => ['nullable', 'numeric', 'min:0', 'max:1'],
        ]);
        $tenantId = (int) $data['tenant_id'];
        $opts = [
            'top_k' => isset($data['top_k']) ? (int) $data['top_k'] : null,
            'mmr_lambda' => isset($data['mmr_lambda']) ? (float) $data['mmr_lambda'] : null,
        ];
        $citations = $kb->retrieveCitations($tenantId, $data['query'], 3, $opts);
        $answer = null;
        if ((bool) ($data['with_answer'] ?? false)) {
            $contextText = '';
            if (!empty($citations)) {
                $contextParts = [];
                foreach ($citations as $c) {
                    $title = $c['title'] ?? ('Doc '.$c['id']);
                    $snippet = trim((string) ($c['snippet'] ?? ''));
                    if ($snippet !== '') {
                        $contextParts[] = "[".$title."]\n".$snippet;
                    }
                }
                if ($contextParts !== []) {
                    $contextText = "\n\nContesto (estratti rilevanti):\n".implode("\n\n---\n\n", $contextParts);
                }
            }
            $payload = [
                'model' => 'gpt-4o-mini',
                'messages' => [
                    ['role' => 'system', 'content' => 'Seleziona solo informazioni dai passaggi forniti nel contesto. Se non sono sufficienti, rispondi: "Non lo so". Riporta sempre le fonti (titoli) usate.'],
                    ['role' => 'user', 'content' => $data['query'].$contextText],
                ],
            ];
            $answer = $chat->chatCompletions($payload)['choices'][0]['message']['content'] ?? '';
        }
        $tenants = Tenant::orderBy('name')->get();
        return view('admin.rag.index', ['tenants' => $tenants, 'result' => compact('citations', 'answer'), 'query' => $data['query'], 'tenant_id' => $tenantId]);
    }
}

