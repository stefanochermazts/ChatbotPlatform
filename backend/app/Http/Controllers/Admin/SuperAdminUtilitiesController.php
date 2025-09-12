<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

class SuperAdminUtilitiesController extends Controller
{
    public function __construct()
    {
        // Assicurati che solo gli admin possano accedere
        $this->middleware('auth.user:admin');
    }

    /**
     * Mostra la pagina utilities per superadmin
     */
    public function index()
    {
        $user = Auth::user();
        
        // Verifica che l'utente sia effettivamente un admin
        if (!$user->isAdmin()) {
            abort(403, 'Accesso riservato ai Super Admin.');
        }

        // Raggruppa le utilities per categoria
        $utilities = $this->getUtilitiesData();
        
        return view('admin.utilities.index', compact('utilities'));
    }

    /**
     * Esegue un comando utility specifico
     */
    public function executeCommand(Request $request)
    {
        $user = Auth::user();
        
        if (!$user->isAdmin()) {
            return response()->json(['error' => 'Accesso negato'], 403);
        }

        $request->validate([
            'command' => 'required|string',
            'parameters' => 'nullable|array',
        ]);

        $command = $request->input('command');
        $parameters = $request->input('parameters', []);

        // Lista dei comandi permessi per sicurezza
        $allowedCommands = [
            'rag:clear-cache',
            'scraper:run',
            'queue:restart',
            'config:clear',
            'config:cache',
        ];

        if (!in_array($command, $allowedCommands)) {
            return response()->json(['error' => 'Comando non autorizzato'], 400);
        }

        try {
            // Esegui il comando Artisan
            $exitCode = Artisan::call($command, $parameters);
            $output = Artisan::output();

            return response()->json([
                'success' => true,
                'exit_code' => $exitCode,
                'output' => $output,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Ottieni i dati strutturati delle utilities
     */
    private function getUtilitiesData(): array
    {
        return [
            'cache_management' => [
                'title' => '🧹 Gestione Cache',
                'description' => 'Strumenti per pulire e gestire la cache del sistema',
                'utilities' => [
                    [
                        'name' => 'Pulisci Cache RAG',
                        'command' => 'php artisan rag:clear-cache',
                        'script' => 'rag:clear-cache',
                        'description' => 'Pulisce tutte le chiavi cache Redis del sistema RAG',
                        'parameters' => [
                            [
                                'name' => '--tenant',
                                'type' => 'number',
                                'description' => 'ID tenant specifico (opzionale)',
                                'example' => '5',
                            ],
                            [
                                'name' => '--dry-run',
                                'type' => 'flag',
                                'description' => 'Mostra cosa verrebbe cancellato senza cancellare',
                            ],
                        ],
                        'examples' => [
                            'php artisan rag:clear-cache --dry-run',
                            'php artisan rag:clear-cache --tenant=5',
                            'php artisan rag:clear-cache',
                        ],
                    ],
                    [
                        'name' => 'Riavvia Code',
                        'command' => 'php artisan queue:restart',
                        'script' => 'queue:restart',
                        'description' => 'Riavvia tutti i worker delle code per applicare nuove configurazioni',
                        'parameters' => [],
                        'examples' => ['php artisan queue:restart'],
                    ],
                ],
            ],
            'diagnostics' => [
                'title' => '🔍 Strumenti di Diagnostica',
                'description' => 'Script per diagnosticare problemi e verificare lo stato del sistema',
                'utilities' => [
                    [
                        'name' => 'Diagnostica RAG Produzione',
                        'command' => 'php diagnose_prod_phone_issue.php',
                        'script' => 'diagnose_prod_phone_issue.php',
                        'description' => 'Script completo per diagnosticare problemi RAG in produzione',
                        'parameters' => [
                            [
                                'name' => 'tenant_id',
                                'type' => 'number',
                                'description' => 'ID del tenant da analizzare',
                                'example' => '5',
                                'required' => true,
                            ],
                            [
                                'name' => 'query',
                                'type' => 'string',
                                'description' => 'Query di test da eseguire',
                                'example' => 'telefono polizia locale',
                                'required' => true,
                            ],
                        ],
                        'examples' => [
                            'php diagnose_prod_phone_issue.php 5 "telefono polizia locale"',
                            'php diagnose_prod_phone_issue.php 1 "orari biblioteca"',
                        ],
                    ],
                    [
                        'name' => 'Test Selezione KB',
                        'command' => 'php test_kb_selection_prod.php',
                        'script' => 'test_kb_selection_prod.php',
                        'description' => 'Testa specificamente la selezione Knowledge Base e confronta risultati',
                        'parameters' => [
                            [
                                'name' => 'tenant_id',
                                'type' => 'number',
                                'description' => 'ID del tenant',
                                'example' => '5',
                                'required' => true,
                            ],
                            [
                                'name' => 'query',
                                'type' => 'string',
                                'description' => 'Query di test',
                                'example' => 'numero vigili urbani',
                                'required' => true,
                            ],
                        ],
                        'examples' => [
                            'php test_kb_selection_prod.php 5 "numero vigili urbani"',
                        ],
                    ],
                    [
                        'name' => 'Verifica Documento',
                        'command' => 'php check_prod_document.php',
                        'script' => 'check_prod_document.php',
                        'description' => 'Verifica il contenuto di un documento specifico per debugging',
                        'parameters' => [],
                        'examples' => ['php check_prod_document.php'],
                        'note' => 'Modifica il doc ID nel file prima di eseguire',
                    ],
                ],
            ],
            'rag_testing' => [
                'title' => '🧪 Test RAG Avanzati',
                'description' => 'Strumenti per testare e ottimizzare il sistema RAG',
                'utilities' => [
                    [
                        'name' => 'Test Multi-KB Boost',
                        'command' => 'php artisan test:multi-kb-boost',
                        'script' => 'TestMultiKbBoost',
                        'description' => 'Confronta risultati tra modalità Single-KB e Multi-KB con boost',
                        'parameters' => [
                            [
                                'name' => 'tenant',
                                'type' => 'number',
                                'description' => 'ID del tenant',
                                'example' => '5',
                                'required' => true,
                            ],
                            [
                                'name' => 'query',
                                'type' => 'string',
                                'description' => 'Query di test (opzionale)',
                                'example' => 'orario vigili urbani',
                            ],
                            [
                                'name' => '--enable-multi-kb',
                                'type' => 'flag',
                                'description' => 'Abilita Multi-KB per il tenant durante il test',
                            ],
                            [
                                'name' => '--debug',
                                'type' => 'flag',
                                'description' => 'Abilita output di debug dettagliato',
                            ],
                        ],
                        'examples' => [
                            'php artisan test:multi-kb-boost 5 --debug',
                            'php artisan test:multi-kb-boost 5 "telefono polizia locale" --enable-multi-kb',
                        ],
                    ],
                ],
            ],
            'scraper_management' => [
                'title' => '🕷️ Gestione Scraper',
                'description' => 'Strumenti per gestire e debuggare il web scraper',
                'utilities' => [
                    [
                        'name' => 'Esegui Scraper',
                        'command' => 'php artisan scraper:run',
                        'script' => 'scraper:run',
                        'description' => 'Esegue una configurazione scraper specifica',
                        'parameters' => [
                            [
                                'name' => 'config_id',
                                'type' => 'number',
                                'description' => 'ID della configurazione scraper',
                                'example' => '1',
                                'required' => true,
                            ],
                        ],
                        'examples' => [
                            'php artisan scraper:run 1',
                        ],
                    ],
                    [
                        'name' => 'Migra Source URL',
                        'command' => 'php artisan scraper:migrate-source-urls',
                        'script' => 'scraper:migrate-source-urls',
                        'description' => 'Migra i source_url per documenti esistenti',
                        'parameters' => [
                            [
                                'name' => '--tenant',
                                'type' => 'number',
                                'description' => 'ID del tenant',
                                'example' => '5',
                                'required' => true,
                            ],
                            [
                                'name' => '--dry-run',
                                'type' => 'flag',
                                'description' => 'Esegue in modalità test senza modificare i dati',
                            ],
                        ],
                        'examples' => [
                            'php artisan scraper:migrate-source-urls --tenant=5 --dry-run',
                            'php artisan scraper:migrate-source-urls --tenant=5',
                        ],
                    ],
                ],
            ],
            'system_maintenance' => [
                'title' => '⚙️ Manutenzione Sistema',
                'description' => 'Comandi per manutenzione e configurazione del sistema',
                'utilities' => [
                    [
                        'name' => 'Pulisci Configurazione',
                        'command' => 'php artisan config:clear',
                        'script' => 'config:clear',
                        'description' => 'Pulisce la cache delle configurazioni Laravel',
                        'parameters' => [],
                        'examples' => ['php artisan config:clear'],
                    ],
                    [
                        'name' => 'Cache Configurazione',
                        'command' => 'php artisan config:cache',
                        'script' => 'config:cache',
                        'description' => 'Crea cache ottimizzata delle configurazioni',
                        'parameters' => [],
                        'examples' => ['php artisan config:cache'],
                    ],
                    [
                        'name' => 'Avvia Worker Code',
                        'command' => 'php artisan queue:work',
                        'script' => 'queue:work',
                        'description' => 'Avvia worker per processare le code (esegui in background)',
                        'parameters' => [
                            [
                                'name' => '--queue',
                                'type' => 'string',
                                'description' => 'Code specifiche da processare',
                                'example' => 'default,ingestion,embeddings,indexing,evaluation',
                            ],
                        ],
                        'examples' => [
                            'php artisan queue:work --queue=default,ingestion,embeddings,indexing,evaluation',
                        ],
                        'note' => 'Comando long-running, eseguire in background o screen/tmux',
                    ],
                ],
            ],
        ];
    }
}
