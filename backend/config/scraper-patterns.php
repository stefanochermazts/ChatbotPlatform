<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Content Extraction Patterns
    |--------------------------------------------------------------------------
    |
    | Patterns per l'estrazione intelligente del contenuto da siti web.
    | Questi pattern sono ordinati per priorità e vengono testati in sequenza.
    |
    */

    'content_patterns' => [
        // Pattern 1: Container di contenuto semantici (CMS italiani)
        [
            'name' => 'testolungo_content',
            'regex' => '/<div[^>]*class="[^"]*testolungo[^"]*"[^>]*>(.*?)<\/div>/is',
            'description' => 'Contenitore testo lungo (CMS italiani come Umbraco, Drupal)',
            'min_length' => 150,
            'priority' => 1
        ],
        [
            'name' => 'description_module',
            'regex' => '/<div[^>]*class="[^"]*descrizione-modulo[^"]*"[^>]*>(.*?)<\/div>/is',
            'description' => 'Contenitore descrizione modulo (PA italiane)',
            'min_length' => 80,
            'priority' => 2
        ],
        
        // Pattern 2: Container di contenuto standard
        [
            'name' => 'content_main_text',
            'regex' => '/<div[^>]*class="[^"]*(?:content-main|main-content|content-text)[^"]*"[^>]*>(.*?)<\/div>/is',
            'description' => 'Contenitore contenuto principale',
            'min_length' => 100,
            'priority' => 3
        ],
        [
            'name' => 'article_body',
            'regex' => '/<div[^>]*class="[^"]*(?:article-body|post-content|entry-content)[^"]*"[^>]*>(.*?)<\/div>/is',
            'description' => 'Corpo articolo/post',
            'min_length' => 100,
            'priority' => 4
        ],
        
        // Pattern 3: Elementi HTML5 semantici
        [
            'name' => 'main_semantic',
            'regex' => '/<main[^>]*>(.*?)<\/main>/is',
            'description' => 'Elemento HTML5 main',
            'min_length' => 100,
            'priority' => 5
        ],
        [
            'name' => 'article_semantic',
            'regex' => '/<article[^>]*>(.*?)<\/article>/is',
            'description' => 'Elemento HTML5 article',
            'min_length' => 100,
            'priority' => 6
        ],
        
        // Pattern 4: CMS specifici
        [
            'name' => 'wordpress_content',
            'regex' => '/<div[^>]*class="[^"]*(?:entry-content|post-content|content)[^"]*"[^>]*>(.*?)<\/div>/is',
            'description' => 'Contenuto WordPress',
            'min_length' => 100,
            'priority' => 7
        ],
        [
            'name' => 'drupal_content',
            'regex' => '/<div[^>]*class="[^"]*(?:field-type-text|node-content|content)[^"]*"[^>]*>(.*?)<\/div>/is',
            'description' => 'Contenuto Drupal',
            'min_length' => 100,
            'priority' => 8
        ]
    ],

    /*
    |--------------------------------------------------------------------------
    | Semantic Container Detection Patterns
    |--------------------------------------------------------------------------
    |
    | Pattern per rilevare se una pagina contiene container semantici
    | che suggeriscono l'uso di estrazione manual DOM.
    |
    */

    'semantic_indicators' => [
        'testolungo',
        'content-main',
        'main-content', 
        'content-text',
        'article-body',
        'post-content',
        'entry-content',
        'descrizione-modulo',
        'field-type-text',
        'node-content'
    ],

    /*
    |--------------------------------------------------------------------------
    | Content Cleaning Rules
    |--------------------------------------------------------------------------
    |
    | Regole per la pulizia del contenuto estratto.
    |
    */

    'cleaning_rules' => [
        'remove_containers' => [
            'nav', 'menu', 'sidebar', 'ads', 'banner', 'footer', 'header',
            'navigation', 'breadcrumb', 'pagination', 'social', 'share'
        ],
        'preserve_elements' => [
            'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li',
            'strong', 'em', 'a', 'blockquote'
        ]
    ]
];
