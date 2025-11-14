<?php

return [
    'api_key' => env('OPENAI_API_KEY', ''),
    'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com'),
    // Modello di default per Chat Completions
    'chat_model' => env('OPENAI_CHAT_MODEL', 'gpt-4o-mini'),
    // Valore di default per il numero massimo di token di completamento (output)
    // Usato dal RAG tester se non specificato dall'utente
    'max_output_tokens' => env('OPENAI_MAX_OUTPUT_TOKENS', 700),
];
