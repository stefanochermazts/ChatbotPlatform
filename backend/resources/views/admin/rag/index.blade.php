@extends('admin.layout')

@section('content')
<h1 class="text-xl font-semibold mb-4">RAG Tester</h1>
<form method="post" action="{{ route('admin.rag.run') }}" class="bg-white border rounded p-4 grid gap-3">
  @csrf
  <div class="grid md:grid-cols-3 gap-3">
    <label class="block">
      <span class="text-sm">Cliente</span>
      <select name="tenant_id" class="w-full border rounded px-3 py-2" required>
        <option value="">Seleziona...</option>
        @foreach($tenants as $t)
          <option value="{{ $t->id }}" @selected(($tenant_id ?? null)===$t->id)>{{ $t->name }} ({{ $t->id }})</option>
        @endforeach
      </select>
    </label>
    <label class="block">
      <span class="text-sm">Top K (chunks)</span>
      <input type="number" name="top_k" min="1" max="50" value="{{ old('top_k', 20) }}" class="w-full border rounded px-3 py-2" />
    </label>
    <label class="block">
      <span class="text-sm">MMR λ (0-1)</span>
      <input type="number" name="mmr_lambda" step="0.05" min="0" max="1" value="{{ old('mmr_lambda', 0.3) }}" class="w-full border rounded px-3 py-2" />
    </label>
    <label class="block">
      <span class="text-sm">Max output tokens</span>
      <input type="number" name="max_output_tokens" min="32" max="8192" value="{{ old('max_output_tokens', config('openai.max_output_tokens', 700)) }}" class="w-full border rounded px-3 py-2" />
    </label>
  </div>
  <label class="block">
    <span class="text-sm">Query</span>
    <textarea name="query" rows="3" class="w-full border rounded px-3 py-2" required>{{ $query ?? '' }}</textarea>
  </label>
  <label class="inline-flex items-center gap-2">
    <input type="checkbox" name="with_answer" value="1" /> Genera risposta con LLM
  </label>
  <div>
    <button class="px-3 py-2 bg-indigo-600 text-white rounded">Esegui</button>
  </div>
</form>

@if(isset($result))
  <div class="mt-6 grid gap-4">
    <div class="bg-white border rounded p-4">
      <h2 class="font-semibold mb-2">Citazioni & Snippet</h2>
      @if(isset($result['trace']['selected_kb']['kb_name']))
        <div class="text-xs mb-2">KB selezionata: <span class="font-semibold">{{ $result['trace']['selected_kb']['kb_name'] }}</span></div>
      @endif
      @if(isset($result['confidence']))
        <div class="text-sm mb-2">Confidence: <span class="font-semibold">{{ number_format($result['confidence']*100,1) }}%</span></div>
      @endif
      @if(isset($result['health']))
        <div class="text-xs mb-2">
          Milvus: 
          @if(($result['health']['ok'] ?? false))
            <span class="text-emerald-700">OK</span>
          @else
            <span class="text-rose-700">KO</span>
            <span class="text-rose-700">{{ $result['health']['error'] ?? '' }}</span>
          @endif
        </div>
      @endif
      @if(empty($result['citations']))
        <p class="text-sm text-gray-600">Nessuna citazione trovata.</p>
      @else
        <ol class="list-decimal list-inside text-sm grid gap-3">
          @foreach($result['citations'] as $c)
            <li>
              <div class="mb-1">
                <a class="text-blue-600 underline" href="{{ $c['url'] }}" target="_blank">{{ $c['title'] ?? ('Doc '.$c['id']) }}</a>
              </div>
              @if(!empty($c['snippet']))
                <blockquote class="p-2 bg-gray-50 border rounded">{{ $c['snippet'] }}</blockquote>
              @endif
              @if(!empty($c['phone']))
                <div class="mt-1 text-sm"><span class="inline-block px-2 py-0.5 bg-emerald-100 text-emerald-800 rounded">📞 Telefono: {{ $c['phone'] }}</span></div>
              @endif
              @if(!empty($c['email']))
                <div class="mt-1 text-sm"><span class="inline-block px-2 py-0.5 bg-blue-100 text-blue-800 rounded">📧 Email: {{ $c['email'] }}</span></div>
              @endif
              @if(!empty($c['address']))
                <div class="mt-1 text-sm"><span class="inline-block px-2 py-0.5 bg-purple-100 text-purple-800 rounded">📍 Indirizzo: {{ $c['address'] }}</span></div>
              @endif
              @if(!empty($c['schedule']))
                <div class="mt-1 text-sm"><span class="inline-block px-2 py-0.5 bg-orange-100 text-orange-800 rounded">🕐 Orario: {{ $c['schedule'] }}</span></div>
              @endif
            </li>
          @endforeach
        </ol>
      @endif
    </div>
    @if(($result['answer'] ?? null) !== null)
    <div class="bg-white border rounded p-4">
      <h2 class="font-semibold mb-2">Risposta</h2>
      @if(isset($result['trace']['selected_kb']['kb_name']))
        <div class="text-xs mb-2">KB selezionata: <span class="font-semibold">{{ $result['trace']['selected_kb']['kb_name'] }}</span></div>
      @endif
      <pre class="whitespace-pre-wrap text-sm">{{ $result['answer'] }}

Fonti:
@foreach(($result['citations'] ?? []) as $c)- {{ $c['title'] ?? ('Doc '.$c['id']) }} ({{ $c['url'] }})
@endforeach</pre>
    </div>
    @endif

    @if(isset($result['trace']))
    <div class="bg-white border rounded p-4">
      <h2 class="font-semibold mb-2">Debug pipeline</h2>
      <details open>
        <summary class="cursor-pointer text-sm">Dettagli</summary>
        <div class="mt-2 grid md:grid-cols-2 gap-4 text-xs">
          @if(!empty($result['trace']['intent_detection']))
          <div class="md:col-span-2 mb-4">
            <h3 class="font-medium text-base text-indigo-700">🎯 Intent Detection</h3>
            <div class="bg-indigo-50 border border-indigo-200 rounded p-3 mt-1">
              <div class="grid md:grid-cols-2 gap-3">
                <div>
                  <h4 class="font-medium text-sm">Query Processing</h4>
                  <div class="text-xs mt-1">
                    <div><strong>Original:</strong> {{ $result['trace']['intent_detection']['original_query'] }}</div>
                    <div><strong>Lowercased:</strong> {{ $result['trace']['intent_detection']['lowercased_query'] }}</div>
                    <div><strong>Expanded:</strong> {{ $result['trace']['intent_detection']['expanded_query'] }}</div>
                  </div>
                </div>
                <div>
                  <h4 class="font-medium text-sm">Intent Results</h4>
                  <div class="text-xs mt-1">
                    <div><strong>Detected:</strong> {{ implode(' > ', $result['trace']['intent_detection']['intents_detected']) }}</div>
                    <div><strong>Executed:</strong> 
                      @if(str_contains($result['trace']['intent_detection']['executed_intent'], '_semantic'))
                        <span class="px-1 py-0.5 bg-purple-100 text-purple-700 rounded">{{ $result['trace']['intent_detection']['executed_intent'] }}</span>
                        <span class="text-xs text-purple-600 ml-1">(semantic)</span>
                      @else
                        <span class="px-1 py-0.5 bg-indigo-100 rounded">{{ $result['trace']['intent_detection']['executed_intent'] }}</span>
                      @endif
                    </div>
                  </div>
                </div>
              </div>
              <div class="mt-3">
                <h4 class="font-medium text-sm">Intent Scores</h4>
                <div class="grid grid-cols-4 gap-2 mt-1 text-xs">
                  @foreach($result['trace']['intent_detection']['intent_scores'] as $intent => $score)
                  <div class="bg-white border rounded p-2">
                    <div class="font-medium">{{ ucfirst($intent) }}</div>
                    <div class="text-lg font-bold {{ $score > 0 ? 'text-green-600' : 'text-gray-400' }}">{{ number_format($score, 3) }}</div>
                  </div>
                  @endforeach
                </div>
              </div>
              <div class="mt-3">
                <h4 class="font-medium text-sm">Keywords Matched</h4>
                <div class="grid grid-cols-4 gap-2 mt-1 text-xs">
                  @foreach($result['trace']['intent_detection']['keywords_matched'] as $intent => $keywords)
                  <div class="bg-white border rounded p-2">
                    <div class="font-medium">{{ ucfirst($intent) }}</div>
                    @if(empty($keywords))
                      <div class="text-gray-400">None</div>
                    @else
                      @foreach($keywords as $kw)
                        <div class="text-xs text-gray-600">{{ $kw }}</div>
                      @endforeach
                    @endif
                  </div>
                  @endforeach
                </div>
              </div>
            </div>
          </div>
          @endif

          @if(!empty($result['trace']['semantic_fallback']))
          <div class="md:col-span-2 mb-4">
            <h3 class="font-medium text-base text-purple-700">🔍 Semantic Fallback</h3>
            <div class="bg-purple-50 border border-purple-200 rounded p-3 mt-1">
              <div class="grid md:grid-cols-2 gap-3 text-xs">
                <div>
                  <h4 class="font-medium text-sm">Original Search</h4>
                  <div class="mt-1">
                    <div><strong>Name:</strong> {{ $result['trace']['semantic_fallback']['original_name'] }}</div>
                    <div><strong>Intent:</strong> {{ $result['trace']['semantic_fallback']['intent_type'] }}</div>
                  </div>
                </div>
                <div>
                  <h4 class="font-medium text-sm">Semantic Query</h4>
                  <div class="bg-white border rounded p-2 mt-1 font-mono text-xs">
                    {{ $result['trace']['semantic_fallback']['semantic_query'] }}
                  </div>
                </div>
              </div>
              
              @if(isset($result['trace']['semantic_fallback']['semantic_results_found']))
              <div class="mt-3 grid md:grid-cols-3 gap-3 text-xs">
                <div>
                  <h4 class="font-medium text-sm">Results Found</h4>
                  <div class="text-lg font-bold {{ $result['trace']['semantic_fallback']['semantic_results_found'] > 0 ? 'text-green-600' : 'text-red-600' }}">
                    {{ $result['trace']['semantic_fallback']['semantic_results_found'] }}
                  </div>
                </div>
                <div>
                  <h4 class="font-medium text-sm">Filtered Results</h4>
                  <div class="text-lg font-bold {{ $result['trace']['semantic_fallback']['filtered_results_count'] > 0 ? 'text-green-600' : 'text-red-600' }}">
                    {{ $result['trace']['semantic_fallback']['filtered_results_count'] ?? 0 }}
                  </div>
                </div>
                <div>
                  <h4 class="font-medium text-sm">Status</h4>
                  @if(isset($result['trace']['semantic_fallback']['failure_reason']))
                    <div class="text-red-600 font-medium">{{ $result['trace']['semantic_fallback']['failure_reason'] }}</div>
                  @else
                    <div class="text-green-600 font-medium">Success</div>
                  @endif
                </div>
              </div>
              
              @if(!empty($result['trace']['semantic_fallback']['top_semantic_hits']))
              <div class="mt-3">
                <h4 class="font-medium text-sm">Top Semantic Hits</h4>
                <div class="bg-white border rounded p-2 mt-1 max-h-32 overflow-auto">
                  @foreach($result['trace']['semantic_fallback']['top_semantic_hits'] as $hit)
                  <div class="text-xs border-b pb-1 mb-1">
                    Doc {{ $hit['document_id'] }}, Chunk {{ $hit['chunk_index'] }}, Score: {{ number_format($hit['score'], 4) }}
                  </div>
                  @endforeach
                </div>
              </div>
              @endif
              
              @if(!empty($result['trace']['semantic_fallback']['citation_debug']))
              <div class="mt-3">
                <h4 class="font-medium text-sm">Citation Debug</h4>
                <div class="bg-white border rounded p-2 mt-1 max-h-40 overflow-auto">
                  @foreach($result['trace']['semantic_fallback']['citation_debug'] as $i => $debug)
                  <div class="text-xs border-b pb-2 mb-2">
                    <div><strong>Result {{ $i }}:</strong></div>
                    <div>Document ID: {{ $debug['document_id'] }}</div>
                    <div>DB Query: <span class="{{ $debug['db_query_result'] === 'found' ? 'text-green-600' : 'text-red-600' }}">{{ $debug['db_query_result'] }}</span></div>
                    <div>Intent Field: {{ $debug['intent_field'] }}</div>
                    @if(isset($debug['skip_reason']))
                    <div class="text-red-600">Skip: {{ $debug['skip_reason'] }}</div>
                    @endif
                    @if(isset($debug['citation_created']))
                    <div class="text-green-600">✅ Citation Created</div>
                    @endif
                  </div>
                  @endforeach
                </div>
              </div>
              @endif
              @endif
              </div>
            </div>
          </div>
          @endif

          <div>
            <h3 class="font-medium">Queries</h3>
            <pre class="bg-gray-50 border rounded p-2">{{ json_encode($result['trace']['queries'] ?? [], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) }}</pre>
          </div>
          <div>
            <h3 class="font-medium">Milvus health</h3>
            <pre class="bg-gray-50 border rounded p-2">{{ json_encode($result['trace']['milvus'] ?? [], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) }}</pre>
          </div>
          <div class="md:col-span-2">
            <h3 class="font-medium">Per query (top hits)</h3>
            <pre class="bg-gray-50 border rounded p-2 max-h-80 overflow-auto">{{ json_encode($result['trace']['per_query'] ?? [], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) }}</pre>
          </div>
          @if(!empty($result['trace']['tenant_prompts']))
          <div class="md:col-span-2 mb-4">
            <h3 class="font-medium text-base text-purple-700">🎨 Tenant Custom Prompts</h3>
            <div class="bg-purple-50 border border-purple-200 rounded p-3 mt-1">
              <div class="grid md:grid-cols-2 gap-3 text-xs">
                <div>
                  <h4 class="font-medium">System Prompt</h4>
                  @if($result['trace']['tenant_prompts']['using_custom_system'])
                    <div class="text-green-600 font-medium">✅ Using Custom</div>
                    <pre class="bg-white border rounded p-2 mt-1 max-h-32 overflow-auto">{{ $result['trace']['tenant_prompts']['custom_system_prompt'] }}</pre>
                  @else
                    <div class="text-gray-600">❌ Using Default</div>
                    <div class="text-xs text-gray-500 mt-1">No custom system prompt configured</div>
                  @endif
                </div>
                <div>
                  <h4 class="font-medium">Context Template</h4>
                  @if($result['trace']['tenant_prompts']['using_custom_context'])
                    <div class="text-green-600 font-medium">✅ Using Custom</div>
                    <pre class="bg-white border rounded p-2 mt-1 max-h-32 overflow-auto">{{ $result['trace']['tenant_prompts']['custom_context_template'] }}</pre>
                  @else
                    <div class="text-gray-600">❌ Using Default</div>
                    <div class="text-xs text-gray-500 mt-1">No custom context template configured</div>
                  @endif
                </div>
              </div>
            </div>
          </div>
          @endif
          @if(!empty($result['trace']['llm_context']))
          <div class="md:col-span-2">
            <h3 class="font-medium">LLM context (testo passato al modello)</h3>
            <pre class="bg-gray-50 border rounded p-2 max-h-80 overflow-auto">{{ $result['trace']['llm_context'] }}</pre>
          </div>
          @endif
          @if(!empty($result['trace']['llm_messages']))
          <div class="md:col-span-2">
            <h3 class="font-medium">LLM messages (payload)</h3>
            <pre class="bg-gray-50 border rounded p-2 max-h-80 overflow-auto">{{ json_encode($result['trace']['llm_messages'], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) }}</pre>
          </div>
          @endif
          <div>
            <h3 class="font-medium">Fused top</h3>
            <pre class="bg-gray-50 border rounded p-2">{{ json_encode($result['trace']['fused_top'] ?? [], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) }}</pre>
          </div>
          <div>
            <h3 class="font-medium">Reranked top</h3>
            <pre class="bg-gray-50 border rounded p-2">{{ json_encode($result['trace']['reranked_top'] ?? [], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) }}</pre>
          </div>
          <div class="md:col-span-2">
            <h3 class="font-medium">MMR selected idx</h3>
            <pre class="bg-gray-50 border rounded p-2">{{ json_encode($result['trace']['mmr_selected_idx'] ?? [], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) }}</pre>
          </div>
        </div>
      </details>
    </div>
    @endif
  </div>
@endif
@endsection

