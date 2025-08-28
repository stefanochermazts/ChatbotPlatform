@extends('admin.layout')

@section('title', 'Configurazione RAG - ' . $tenant->name)

@section('content')
<div class="max-w-6xl mx-auto py-6 sm:px-6 lg:px-8">
    <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
        <div class="p-6 bg-white border-b border-gray-200">
            <!-- Header -->
            <div class="flex justify-between items-center mb-6">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">Configurazione RAG</h1>
                    <p class="text-gray-600 mt-1">Personalizza i parametri di ricerca e generazione per {{ $tenant->name }}</p>
                </div>
                <div class="flex space-x-3">
                    <button id="test-config-btn" type="button" 
                            class="inline-flex items-center px-4 py-2 border border-green-300 shadow-sm text-sm font-medium rounded-md text-green-700 bg-green-50 hover:bg-green-100 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500">
                        🧪 Test Configurazione
                    </button>
                    <a href="{{ route('admin.tenants.edit', $tenant) }}" 
                       class="inline-flex items-center px-4 py-2 border border-gray-300 shadow-sm text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                        ← Torna al Tenant
                    </a>
                </div>
            </div>

            <!-- Profile Selector -->
            <form id="rag-config-form" method="POST" action="{{ route('admin.rag-config.update', $tenant) }}">
                @csrf
                <div class="mb-6 p-4 bg-blue-50 rounded-lg">
                    <label for="rag_profile" class="block text-sm font-medium text-gray-700 mb-2">
                        Profilo RAG Predefinito
                    </label>
                    <select name="rag_profile" id="rag_profile" onchange="loadProfileTemplate()"
                            class="block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        <option value="custom" {{ ($tenant->rag_profile ?? 'custom') === 'custom' ? 'selected' : '' }}>
                            🛠️ Personalizzato
                        </option>
                        <option value="public_administration" {{ $tenant->rag_profile === 'public_administration' ? 'selected' : '' }}>
                            🏛️ Pubblica Amministrazione
                        </option>
                        <option value="ecommerce" {{ $tenant->rag_profile === 'ecommerce' ? 'selected' : '' }}>
                            🛍️ E-commerce
                        </option>
                        <option value="customer_service" {{ $tenant->rag_profile === 'customer_service' ? 'selected' : '' }}>
                            📞 Customer Service
                        </option>
                    </select>
                    <p class="text-xs text-gray-600 mt-1">
                        Seleziona un profilo predefinito o personalizza manualmente i parametri
                    </p>
                </div>

                <!-- Tabs Navigation -->
                <div class="border-b border-gray-200 mb-6">
                    <nav class="-mb-px flex space-x-8" aria-label="Tabs">
                        <button type="button" onclick="showTab('hybrid')" id="tab-hybrid"
                                class="tab-button whitespace-nowrap py-2 px-1 border-b-2 font-medium text-sm border-blue-500 text-blue-600">
                            🔍 Ricerca Ibrida
                        </button>
                        <button type="button" onclick="showTab('answer')" id="tab-answer"
                                class="tab-button whitespace-nowrap py-2 px-1 border-b-2 font-medium text-sm border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300">
                            💬 Soglie Risposta
                        </button>
                        <button type="button" onclick="showTab('reranker')" id="tab-reranker"
                                class="tab-button whitespace-nowrap py-2 px-1 border-b-2 font-medium text-sm border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300">
                            🎯 Reranking
                        </button>
                        <button type="button" onclick="showTab('advanced')" id="tab-advanced"
                                class="tab-button whitespace-nowrap py-2 px-1 border-b-2 font-medium text-sm border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300">
                            🚀 Avanzate
                        </button>
                        <button type="button" onclick="showTab('intents')" id="tab-intents"
                                class="tab-button whitespace-nowrap py-2 px-1 border-b-2 font-medium text-sm border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300">
                            🎭 Intent
                        </button>
                    </nav>
                </div>

                <!-- Tab: Hybrid Search -->
                <div id="content-hybrid" class="tab-content">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label for="vector_top_k" class="block text-sm font-medium text-gray-700">Vector Top K</label>
                            <input type="number" name="vector_top_k" id="vector_top_k" min="1" max="200"
                                   value="{{ $currentConfig['hybrid']['vector_top_k'] ?? 40 }}"
                                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            <p class="text-xs text-gray-600 mt-1">Risultati vettoriali per query (1-200)</p>
                        </div>

                        <div>
                            <label for="bm25_top_k" class="block text-sm font-medium text-gray-700">BM25 Top K</label>
                            <input type="number" name="bm25_top_k" id="bm25_top_k" min="1" max="300"
                                   value="{{ $currentConfig['hybrid']['bm25_top_k'] ?? 80 }}"
                                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            <p class="text-xs text-gray-600 mt-1">Risultati BM25 per query (1-300)</p>
                        </div>

                        <div>
                            <label for="rrf_k" class="block text-sm font-medium text-gray-700">RRF K</label>
                            <input type="number" name="rrf_k" id="rrf_k" min="10" max="100"
                                   value="{{ $currentConfig['hybrid']['rrf_k'] ?? 60 }}"
                                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            <p class="text-xs text-gray-600 mt-1">Parametro fusion RRF (10-100)</p>
                        </div>

                        <div>
                            <label for="mmr_lambda" class="block text-sm font-medium text-gray-700">MMR Lambda</label>
                            <input type="number" name="mmr_lambda" id="mmr_lambda" min="0" max="1" step="0.05"
                                   value="{{ $currentConfig['hybrid']['mmr_lambda'] ?? 0.25 }}"
                                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            <p class="text-xs text-gray-600 mt-1">Balance rilevanza/diversità (0=diversità, 1=rilevanza)</p>
                        </div>

                        <div>
                            <label for="mmr_take" class="block text-sm font-medium text-gray-700">MMR Take</label>
                            <input type="number" name="mmr_take" id="mmr_take" min="1" max="50"
                                   value="{{ $currentConfig['hybrid']['mmr_take'] ?? 10 }}"
                                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            <p class="text-xs text-gray-600 mt-1">Documenti finali selezionati (1-50)</p>
                        </div>

                        <div>
                            <label for="neighbor_radius" class="block text-sm font-medium text-gray-700">Neighbor Radius</label>
                            <input type="number" name="neighbor_radius" id="neighbor_radius" min="0" max="10"
                                   value="{{ $currentConfig['hybrid']['neighbor_radius'] ?? 2 }}"
                                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            <p class="text-xs text-gray-600 mt-1">Chunk adiacenti da includere (0-10)</p>
                        </div>
                    </div>

                    <!-- Multi-Query Section -->
                    <div class="mt-6 p-4 bg-gray-50 rounded-lg">
                        <h3 class="text-lg font-medium text-gray-900 mb-4">Multi-Query Expansion</h3>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div>
                                <label class="flex items-center">
                                    <input type="checkbox" name="multiquery_enabled" id="multiquery_enabled"
                                           {{ ($currentConfig['multiquery']['enabled'] ?? true) ? 'checked' : '' }}
                                           class="rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                    <span class="ml-2 text-sm text-gray-700">Abilita Multi-Query</span>
                                </label>
                            </div>
                            <div>
                                <label for="multiquery_num" class="block text-sm font-medium text-gray-700">Numero Query</label>
                                <input type="number" name="multiquery_num" id="multiquery_num" min="1" max="10"
                                       value="{{ $currentConfig['multiquery']['num'] ?? 3 }}"
                                       class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            </div>
                            <div>
                                <label for="multiquery_temperature" class="block text-sm font-medium text-gray-700">Temperature</label>
                                <input type="number" name="multiquery_temperature" id="multiquery_temperature" min="0" max="1" step="0.1"
                                       value="{{ $currentConfig['multiquery']['temperature'] ?? 0.3 }}"
                                       class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tab: Answer Thresholds -->
                <div id="content-answer" class="tab-content hidden">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label for="min_citations" class="block text-sm font-medium text-gray-700">Citazioni Minime</label>
                            <input type="number" name="min_citations" id="min_citations" min="0" max="10"
                                   value="{{ $currentConfig['answer']['min_citations'] ?? 1 }}"
                                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            <p class="text-xs text-gray-600 mt-1">Numero minimo di citazioni per risposta</p>
                        </div>

                        <div>
                            <label for="min_confidence" class="block text-sm font-medium text-gray-700">Confidence Minima</label>
                            <input type="number" name="min_confidence" id="min_confidence" min="0" max="1" step="0.01"
                                   value="{{ $currentConfig['answer']['min_confidence'] ?? 0.08 }}"
                                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            <p class="text-xs text-gray-600 mt-1">Soglia confidence per risposta (0-1)</p>
                        </div>

                        <div class="md:col-span-2">
                            <label class="flex items-center">
                                <input type="checkbox" name="force_if_has_citations" id="force_if_has_citations"
                                       {{ ($currentConfig['answer']['force_if_has_citations'] ?? true) ? 'checked' : '' }}
                                       class="rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <span class="ml-2 text-sm text-gray-700">Forza risposta se ci sono citazioni</span>
                            </label>
                        </div>

                        <div class="md:col-span-2">
                            <label for="fallback_message" class="block text-sm font-medium text-gray-700">Messaggio di Fallback</label>
                            <textarea name="fallback_message" id="fallback_message" rows="3"
                                      class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                      placeholder="Messaggio quando non si trova risposta adeguata">{{ $currentConfig['answer']['fallback_message'] ?? 'Non lo so con certezza: non trovo riferimenti sufficienti nella base di conoscenza.' }}</textarea>
                        </div>
                    </div>
                </div>

                <!-- Tab: Reranker -->
                <div id="content-reranker" class="tab-content hidden">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label for="reranker_driver" class="block text-sm font-medium text-gray-700">Driver Reranker</label>
                            <select name="reranker_driver" id="reranker_driver"
                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <option value="embedding" {{ ($currentConfig['reranker']['driver'] ?? 'embedding') === 'embedding' ? 'selected' : '' }}>
                                    🧮 Embedding (Veloce)
                                </option>
                                <option value="cohere" {{ ($currentConfig['reranker']['driver'] ?? 'embedding') === 'cohere' ? 'selected' : '' }}>
                                    🎯 Cohere (Accurato)
                                </option>
                                <option value="llm" {{ ($currentConfig['reranker']['driver'] ?? 'embedding') === 'llm' ? 'selected' : '' }}>
                                    🤖 LLM (Molto Accurato)
                                </option>
                                <option value="none" {{ ($currentConfig['reranker']['driver'] ?? 'embedding') === 'none' ? 'selected' : '' }}>
                                    ❌ Disabilitato
                                </option>
                            </select>
                            <p class="text-xs text-gray-600 mt-1">Strategia di riordino risultati</p>
                        </div>

                        <div>
                            <label for="reranker_top_n" class="block text-sm font-medium text-gray-700">Top N Candidati</label>
                            <input type="number" name="reranker_top_n" id="reranker_top_n" min="1" max="100"
                                   value="{{ $currentConfig['reranker']['top_n'] ?? 40 }}"
                                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            <p class="text-xs text-gray-600 mt-1">Candidati per reranking (1-100)</p>
                        </div>
                    </div>

                    <!-- Context Settings -->
                    <div class="mt-6 p-4 bg-gray-50 rounded-lg">
                        <h3 class="text-lg font-medium text-gray-900 mb-4">Context Building</h3>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div>
                                <label for="context_max_chars" class="block text-sm font-medium text-gray-700">Max Caratteri</label>
                                <input type="number" name="context_max_chars" id="context_max_chars" min="1000" max="20000"
                                       value="{{ $currentConfig['context']['max_chars'] ?? 6000 }}"
                                       class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            </div>
                            <div>
                                <label for="compress_if_over_chars" class="block text-sm font-medium text-gray-700">Comprimi se > Chars</label>
                                <input type="number" name="compress_if_over_chars" id="compress_if_over_chars" min="1000" max="25000"
                                       value="{{ $currentConfig['context']['compress_if_over_chars'] ?? 7000 }}"
                                       class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            </div>
                            <div>
                                <label for="compress_target_chars" class="block text-sm font-medium text-gray-700">Target Compressione</label>
                                <input type="number" name="compress_target_chars" id="compress_target_chars" min="500" max="15000"
                                       value="{{ $currentConfig['context']['compress_target_chars'] ?? 3500 }}"
                                       class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tab: Advanced -->
                <div id="content-advanced" class="tab-content hidden">
                    <!-- HyDE Settings -->
                    <div class="p-4 bg-purple-50 rounded-lg mb-6">
                        <h3 class="text-lg font-medium text-gray-900 mb-4">🔮 HyDE (Hypothetical Document Embeddings)</h3>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div>
                                <label class="flex items-center">
                                    <input type="checkbox" name="hyde_enabled" id="hyde_enabled"
                                           {{ ($currentConfig['advanced']['hyde']['enabled'] ?? false) ? 'checked' : '' }}
                                           class="rounded border-gray-300 text-purple-600 shadow-sm focus:border-purple-500 focus:ring-purple-500">
                                    <span class="ml-2 text-sm text-gray-700">Abilita HyDE</span>
                                </label>
                            </div>
                            <div>
                                <label for="hyde_weight_original" class="block text-sm font-medium text-gray-700">Peso Originale</label>
                                <input type="number" name="hyde_weight_original" id="hyde_weight_original" min="0" max="1" step="0.1"
                                       value="{{ $currentConfig['advanced']['hyde']['weight_original'] ?? 0.6 }}"
                                       class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-purple-500 focus:ring-purple-500">
                            </div>
                            <div>
                                <label for="hyde_weight_hypothetical" class="block text-sm font-medium text-gray-700">Peso Ipotetico</label>
                                <input type="number" name="hyde_weight_hypothetical" id="hyde_weight_hypothetical" min="0" max="1" step="0.1"
                                       value="{{ $currentConfig['advanced']['hyde']['weight_hypothetical'] ?? 0.4 }}"
                                       class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-purple-500 focus:ring-purple-500">
                            </div>
                        </div>
                    </div>

                    <!-- LLM Reranker Settings -->
                    <div class="p-4 bg-orange-50 rounded-lg">
                        <h3 class="text-lg font-medium text-gray-900 mb-4">🤖 LLM Reranker</h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="flex items-center">
                                    <input type="checkbox" name="llm_reranker_enabled" id="llm_reranker_enabled"
                                           {{ ($currentConfig['advanced']['llm_reranker']['enabled'] ?? false) ? 'checked' : '' }}
                                           class="rounded border-gray-300 text-orange-600 shadow-sm focus:border-orange-500 focus:ring-orange-500">
                                    <span class="ml-2 text-sm text-gray-700">Abilita LLM Reranking</span>
                                </label>
                            </div>
                            <div>
                                <label for="llm_reranker_batch_size" class="block text-sm font-medium text-gray-700">Batch Size</label>
                                <input type="number" name="llm_reranker_batch_size" id="llm_reranker_batch_size" min="1" max="20"
                                       value="{{ $currentConfig['advanced']['llm_reranker']['batch_size'] ?? 5 }}"
                                       class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-orange-500 focus:ring-orange-500">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tab: Intents -->
                <div id="content-intents" class="tab-content hidden">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <!-- Intent Enablement -->
                        <div class="p-4 bg-blue-50 rounded-lg">
                            <h3 class="text-lg font-medium text-gray-900 mb-4">Intent Attivi</h3>
                            <div class="space-y-2">
                                <label class="flex items-center">
                                    <input type="checkbox" name="intent_thanks" id="intent_thanks"
                                           {{ ($currentConfig['intents']['enabled']['thanks'] ?? true) ? 'checked' : '' }}
                                           class="rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                    <span class="ml-2 text-sm text-gray-700">🙏 Thanks</span>
                                </label>
                                <label class="flex items-center">
                                    <input type="checkbox" name="intent_phone" id="intent_phone"
                                           {{ ($currentConfig['intents']['enabled']['phone'] ?? true) ? 'checked' : '' }}
                                           class="rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                    <span class="ml-2 text-sm text-gray-700">📞 Phone</span>
                                </label>
                                <label class="flex items-center">
                                    <input type="checkbox" name="intent_email" id="intent_email"
                                           {{ ($currentConfig['intents']['enabled']['email'] ?? true) ? 'checked' : '' }}
                                           class="rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                    <span class="ml-2 text-sm text-gray-700">📧 Email</span>
                                </label>
                                <label class="flex items-center">
                                    <input type="checkbox" name="intent_address" id="intent_address"
                                           {{ ($currentConfig['intents']['enabled']['address'] ?? true) ? 'checked' : '' }}
                                           class="rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                    <span class="ml-2 text-sm text-gray-700">📍 Address</span>
                                </label>
                                <label class="flex items-center">
                                    <input type="checkbox" name="intent_schedule" id="intent_schedule"
                                           {{ ($currentConfig['intents']['enabled']['schedule'] ?? true) ? 'checked' : '' }}
                                           class="rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                    <span class="ml-2 text-sm text-gray-700">🕒 Schedule</span>
                                </label>
                            </div>
                        </div>

                        <!-- Intent Settings -->
                        <div>
                            <div class="mb-4">
                                <label for="intent_min_score" class="block text-sm font-medium text-gray-700">Score Minimo Intent</label>
                                <input type="number" name="intent_min_score" id="intent_min_score" min="0" max="1" step="0.05"
                                       value="{{ $currentConfig['intents']['min_score'] ?? 0.5 }}"
                                       class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <p class="text-xs text-gray-600 mt-1">Soglia minima per rilevare intent</p>
                            </div>

                            <div>
                                <label for="intent_execution_strategy" class="block text-sm font-medium text-gray-700">Strategia Esecuzione</label>
                                <select name="intent_execution_strategy" id="intent_execution_strategy"
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                    <option value="priority_based" {{ ($currentConfig['intents']['execution_strategy'] ?? 'priority_based') === 'priority_based' ? 'selected' : '' }}>
                                        🎯 Basata su Score
                                    </option>
                                    <option value="first_match" {{ ($currentConfig['intents']['execution_strategy'] ?? 'priority_based') === 'first_match' ? 'selected' : '' }}>
                                        ⚡ Primo Match
                                    </option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- KB Selection -->
                    <div class="mt-6 p-4 bg-green-50 rounded-lg">
                        <h3 class="text-lg font-medium text-gray-900 mb-4">Selezione Knowledge Base</h3>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div>
                                <label for="kb_selection_mode" class="block text-sm font-medium text-gray-700">Modalità Selezione</label>
                                <select name="kb_selection_mode" id="kb_selection_mode"
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500">
                                    <option value="auto" {{ ($currentConfig['kb_selection']['mode'] ?? 'auto') === 'auto' ? 'selected' : '' }}>🤖 Automatica</option>
                                    <option value="strict" {{ ($currentConfig['kb_selection']['mode'] ?? 'auto') === 'strict' ? 'selected' : '' }}>🔒 Rigorosa</option>
                                    <option value="multi" {{ ($currentConfig['kb_selection']['mode'] ?? 'auto') === 'multi' ? 'selected' : '' }}>🌐 Multi-KB</option>
                                </select>
                            </div>
                            <div>
                                <label for="bm25_boost_factor" class="block text-sm font-medium text-gray-700">Boost BM25</label>
                                <input type="number" name="bm25_boost_factor" id="bm25_boost_factor" min="0.1" max="5" step="0.1"
                                       value="{{ $currentConfig['kb_selection']['bm25_boost_factor'] ?? 1.0 }}"
                                       class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500">
                            </div>
                            <div>
                                <label for="vector_boost_factor" class="block text-sm font-medium text-gray-700">Boost Vector</label>
                                <input type="number" name="vector_boost_factor" id="vector_boost_factor" min="0.1" max="5" step="0.1"
                                       value="{{ $currentConfig['kb_selection']['vector_boost_factor'] ?? 1.0 }}"
                                       class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Action Buttons -->
                <div class="flex justify-between items-center pt-6 border-t border-gray-200">
                    <button type="button" onclick="resetToDefaults()" 
                            class="inline-flex items-center px-4 py-2 border border-red-300 shadow-sm text-sm font-medium rounded-md text-red-700 bg-red-50 hover:bg-red-100 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500">
                        🔄 Ripristina Default
                    </button>
                    
                    <button type="submit" 
                            class="inline-flex items-center px-6 py-3 border border-transparent text-base font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                        💾 Salva Configurazione
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Test Results Modal -->
    <div id="test-modal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden">
        <div class="relative top-20 mx-auto p-5 border w-11/12 max-w-4xl shadow-lg rounded-md bg-white">
            <div class="mt-3">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-lg font-medium text-gray-900">Risultati Test Configurazione</h3>
                    <button onclick="closeTestModal()" class="text-gray-400 hover:text-gray-600">
                        <span class="sr-only">Chiudi</span>
                        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
                <div id="test-results-content">
                    <!-- Results will be loaded here -->
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// CSRF Token setup
window.csrfToken = '{{ csrf_token() }}';

// Tab Management
function showTab(tabName) {
    // Hide all tab contents
    document.querySelectorAll('.tab-content').forEach(content => {
        content.classList.add('hidden');
    });
    
    // Show selected tab content
    document.getElementById('content-' + tabName).classList.remove('hidden');
    
    // Update tab buttons
    document.querySelectorAll('.tab-button').forEach(button => {
        button.classList.remove('border-blue-500', 'text-blue-600');
        button.classList.add('border-transparent', 'text-gray-500');
    });
    
    document.getElementById('tab-' + tabName).classList.remove('border-transparent', 'text-gray-500');
    document.getElementById('tab-' + tabName).classList.add('border-blue-500', 'text-blue-600');
}

// Load Profile Template
async function loadProfileTemplate() {
    const profileSelect = document.getElementById('rag_profile');
    const profile = profileSelect.value;
    
    if (profile === 'custom') return;
    
    try {
        const response = await fetch(`{{ route('admin.rag-config.profile-template') }}?profile=${profile}`);
        const data = await response.json();
        
        if (data.success && data.template) {
            populateFormWithTemplate(data.template);
        }
    } catch (error) {
        console.error('Error loading profile template:', error);
    }
}

// Populate Form with Template
function populateFormWithTemplate(template) {
    // Hybrid settings
    if (template.hybrid) {
        Object.keys(template.hybrid).forEach(key => {
            const field = document.getElementById(key);
            if (field) field.value = template.hybrid[key];
        });
    }
    
    // Answer settings
    if (template.answer) {
        Object.keys(template.answer).forEach(key => {
            const field = document.getElementById(key);
            if (field) {
                if (field.type === 'checkbox') {
                    field.checked = template.answer[key];
                } else {
                    field.value = template.answer[key];
                }
            }
        });
    }
    
    // Reranker settings
    if (template.reranker) {
        Object.keys(template.reranker).forEach(key => {
            const field = document.getElementById('reranker_' + key);
            if (field) field.value = template.reranker[key];
        });
    }
    
    // Add more template population as needed...
}

// Test Configuration
async function testConfig() {
    const button = document.getElementById('test-config-btn');
    const originalText = button.innerHTML;
    button.innerHTML = '⏳ Testing...';
    button.disabled = true;
    
    console.log('Starting test config...');
    console.log('CSRF Token:', window.csrfToken);
    
    try {
        const response = await fetch(`{{ route('admin.rag-config.test', $tenant) }}`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': window.csrfToken
            },
            body: JSON.stringify({
                query: 'orario vigili urbani'
            })
        });
        
        console.log('Response status:', response.status);
        console.log('Response ok:', response.ok);
        
        if (!response.ok) {
            const errorText = await response.text();
            console.log('Response error text:', errorText);
            throw new Error(`HTTP ${response.status}: ${errorText}`);
        }
        
        const data = await response.json();
        console.log('Response data:', data);
        showTestResults(data);
        
    } catch (error) {
        console.error('Test error:', error);
        showTestResults({
            success: false,
            error: 'Errore durante il test: ' + error.message
        });
    } finally {
        button.innerHTML = originalText;
        button.disabled = false;
    }
}

// Show Test Results
function showTestResults(data) {
    const modal = document.getElementById('test-modal');
    const content = document.getElementById('test-results-content');
    
    if (data.success) {
        content.innerHTML = `
            <div class="bg-green-50 border border-green-200 rounded-md p-4 mb-4">
                <div class="flex">
                    <div class="flex-shrink-0">
                        <svg class="h-5 w-5 text-green-400" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                        </svg>
                    </div>
                    <div class="ml-3">
                        <h3 class="text-sm font-medium text-green-800">Test Completato</h3>
                        <div class="mt-2 text-sm text-green-700">
                            <p><strong>Citazioni trovate:</strong> ${data.citations}</p>
                            <p><strong>Confidence:</strong> ${(data.confidence * 100).toFixed(1)}%</p>
                        </div>
                    </div>
                </div>
            </div>
            
            ${data.debug ? `<details class="mt-4">
                <summary class="cursor-pointer text-sm font-medium text-gray-700">Debug Info</summary>
                <pre class="mt-2 text-xs bg-gray-100 p-3 rounded overflow-auto">${JSON.stringify(data.debug, null, 2)}</pre>
            </details>` : ''}
        `;
    } else {
        content.innerHTML = `
            <div class="bg-red-50 border border-red-200 rounded-md p-4">
                <div class="flex">
                    <div class="flex-shrink-0">
                        <svg class="h-5 w-5 text-red-400" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />
                        </svg>
                    </div>
                    <div class="ml-3">
                        <h3 class="text-sm font-medium text-red-800">Test Fallito</h3>
                        <div class="mt-2 text-sm text-red-700">
                            <p>${data.error}</p>
                        </div>
                    </div>
                </div>
            </div>
        `;
    }
    
    modal.classList.remove('hidden');
}

// Close Test Modal
function closeTestModal() {
    document.getElementById('test-modal').classList.add('hidden');
}

// Reset to Defaults
async function resetToDefaults() {
    if (!confirm('Sei sicuro di voler ripristinare tutte le impostazioni ai valori di default?')) {
        return;
    }
    
    try {
        const response = await fetch(`{{ route('admin.rag-config.reset', $tenant) }}`, {
            method: 'DELETE',
            headers: {
                'X-CSRF-TOKEN': window.csrfToken
            }
        });
        
        if (response.ok) {
            location.reload();
        }
    } catch (error) {
        alert('Errore durante il ripristino: ' + error.message);
    }
}

// Event Listeners
document.getElementById('test-config-btn').addEventListener('click', testConfig);

// Close modal when clicking outside
document.getElementById('test-modal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeTestModal();
    }
});
</script>
@endsection
