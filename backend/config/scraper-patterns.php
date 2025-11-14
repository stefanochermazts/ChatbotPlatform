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
            'priority' => 1,
        ],
        [
            'name' => 'description_module',
            'regex' => '/<div[^>]*class="[^"]*descrizione-modulo[^"]*"[^>]*>(.*?)<\/div>/is',
            'description' => 'Contenitore descrizione modulo (PA italiane)',
            'min_length' => 80,
            'priority' => 2,
        ],

        // 🚀 PATTERN ANGULAR/SPA SPECIFICI (PRIORITÀ ALTA)
        [
            'name' => 'angular_app_latest_article',
            'regex' => '/<app-latest-article[^>]*>(.*?)<\/app-latest-article>/is',
            'description' => 'Contenuto componente Angular latest-article',
            'min_length' => 200,
            'priority' => 1.0,
        ],
        [
            'name' => 'angular_app_treelist',
            'regex' => '/<app-treelist[^>]*>(.*?)<\/app-treelist>/is',
            'description' => 'Contenuto lista/tree Angular',
            'min_length' => 150,
            'priority' => 1.1,
        ],
        [
            'name' => 'angular_app_switcher',
            'regex' => '/<app-switcher[^>]*>(.*?)<\/app-switcher>/is',
            'description' => 'Contenuto switcher dinamico Angular',
            'min_length' => 100,
            'priority' => 1.2,
        ],
        [
            'name' => 'angular_app_pagination',
            'regex' => '/<app-pagination[^>]*>(.*?)<\/app-pagination>/is',
            'description' => 'Informazioni paginazione e risultati Angular',
            'min_length' => 20,
            'priority' => 1.3,
        ],
        [
            'name' => 'angular_app_preview_image',
            'regex' => '/<app-preview-image[^>]*>(.*?)<\/app-preview-image>/is',
            'description' => 'Contenuto componente preview immagini Angular',
            'min_length' => 30,
            'priority' => 1.4,
        ],
        [
            'name' => 'angular_app_md_structure',
            'regex' => '/<app-md-structure[^>]*>(.*?)<\/app-md-structure>/is',
            'description' => 'Contenuto struttura markdown Angular',
            'min_length' => 80,
            'priority' => 1.5,
        ],

        // 🎯 PATTERN BOOTSTRAP/CSS FRAMEWORK (PRIORITÀ MEDIA)
        [
            'name' => 'bootstrap_card_body',
            'regex' => '/<div[^>]*class="[^"]*card-body[^"]*"[^>]*>(.*?)<\/div>/is',
            'description' => 'Contenuto cards Bootstrap',
            'min_length' => 40,
            'priority' => 2.0,
        ],
        [
            'name' => 'bootstrap_card_title',
            'regex' => '/<h[1-6][^>]*class="[^"]*card-title[^"]*"[^>]*>(.*?)<\/h[1-6]>/is',
            'description' => 'Titoli cards Bootstrap',
            'min_length' => 10,
            'priority' => 2.1,
        ],
        [
            'name' => 'bootstrap_container_content',
            'regex' => '/<div[^>]*class="[^"]*container[^"]*"[^>]*>(.*?)<\/div>/is',
            'description' => 'Contenuto container Bootstrap',
            'min_length' => 200,
            'priority' => 2.2,
        ],
        [
            'name' => 'bootstrap_row_content',
            'regex' => '/<div[^>]*class="[^"]*row[^"]*"[^>]*>(.*?)<\/div>/is',
            'description' => 'Contenuto righe Bootstrap',
            'min_length' => 150,
            'priority' => 2.3,
        ],
        [
            'name' => 'bootstrap_text_secondary',
            'regex' => '/<p[^>]*class="[^"]*text-secondary[^"]*"[^>]*>(.*?)<\/p>/is',
            'description' => 'Testo secondario Bootstrap',
            'min_length' => 20,
            'priority' => 2.4,
        ],

        // 🌟 PATTERN SEMANTICI HTML5 (PRIORITÀ ALTA)
        [
            'name' => 'semantic_main_id',
            'regex' => '/<div[^>]*id="main"[^>]*>(.*?)<\/div>/is',
            'description' => 'Contenuto main con ID semantico (comune nei CMS PA italiane)',
            'min_length' => 200,
            'priority' => 1.0,
        ],
        [
            'name' => 'semantic_main_role',
            'regex' => '/<div[^>]*role="main"[^>]*>(.*?)<\/div>/is',
            'description' => 'Contenuto main con ruolo semantico',
            'min_length' => 300,
            'priority' => 1.6,
        ],
        [
            'name' => 'semantic_article_content',
            'regex' => '/<article[^>]*>(.*?)<\/article>/is',
            'description' => 'Contenuto tag article HTML5',
            'min_length' => 200,
            'priority' => 1.7,
        ],
        [
            'name' => 'semantic_section_content',
            'regex' => '/<section[^>]*>(.*?)<\/section>/is',
            'description' => 'Contenuto tag section HTML5',
            'min_length' => 150,
            'priority' => 1.8,
        ],
        [
            'name' => 'semantic_main_tag',
            'regex' => '/<main[^>]*>(.*?)<\/main>/is',
            'description' => 'Contenuto tag main HTML5',
            'min_length' => 400,
            'priority' => 1.0,
        ],
        [
            'name' => 'semantic_header_content',
            'regex' => '/<header[^>]*>(.*?)<\/header>/is',
            'description' => 'Contenuto header HTML5',
            'min_length' => 50,
            'priority' => 2.5,
        ],

        // 📋 PATTERN FORM E INTERAZIONE (PRIORITÀ MEDIA)
        [
            'name' => 'form_input_labels',
            'regex' => '/<label[^>]*>(.*?)<\/label>/is',
            'description' => 'Etichette form',
            'min_length' => 5,
            'priority' => 3.0,
        ],
        [
            'name' => 'form_placeholders',
            'regex' => '/placeholder="([^"]+)"/is',
            'description' => 'Placeholder form input',
            'min_length' => 3,
            'priority' => 3.1,
        ],

        // 🏷️ PATTERN METADATI E CATEGORIZZAZIONE (PRIORITÀ BASSA)
        [
            'name' => 'category_links',
            'regex' => '/<a[^>]*class="[^"]*(?:category|tag|badge)[^"]*"[^>]*>(.*?)<\/a>/is',
            'description' => 'Link categorie e tag',
            'min_length' => 3,
            'priority' => 3.2,
        ],
        [
            'name' => 'date_metadata',
            'regex' => '/<span[^>]*class="[^"]*(?:data|date|time)[^"]*"[^>]*>(.*?)<\/span>/is',
            'description' => 'Metadati data e ora',
            'min_length' => 5,
            'priority' => 3.3,
        ],

        // 🖼️ PATTERN MEDIA E DESCRIZIONI (PRIORITÀ MEDIA)
        [
            'name' => 'image_alt_text',
            'regex' => '/alt="([^"]+)"/is',
            'description' => 'Testo alternativo immagini',
            'min_length' => 5,
            'priority' => 2.8,
        ],
        [
            'name' => 'image_figure_caption',
            'regex' => '/<figcaption[^>]*>(.*?)<\/figcaption>/is',
            'description' => 'Didascalie immagini',
            'min_length' => 10,
            'priority' => 2.9,
        ],

        // 🎨 PATTERN CMS E FRAMEWORK MODERNI (PRIORITÀ MEDIA)
        [
            'name' => 'wordpress_entry_content',
            'regex' => '/<div[^>]*class="[^"]*entry-content[^"]*"[^>]*>(.*?)<\/div>/is',
            'description' => 'Contenuto articoli WordPress',
            'min_length' => 200,
            'priority' => 2.6,
        ],
        [
            'name' => 'drupal_field_content',
            'regex' => '/<div[^>]*class="[^"]*field[^"]*"[^>]*>(.*?)<\/div>/is',
            'description' => 'Campi contenuto Drupal',
            'min_length' => 50,
            'priority' => 2.7,
        ],
        [
            'name' => 'vue_component',
            'regex' => '/<[a-z-]+[^>]*v-[^>]*>(.*?)<\/[a-z-]+>/is',
            'description' => 'Componenti Vue.js con direttive',
            'min_length' => 50,
            'priority' => 1.9,
        ],
        [
            'name' => 'react_component_props',
            'regex' => '/<div[^>]*data-reactroot[^>]*>(.*?)<\/div>/is',
            'description' => 'Componenti React root',
            'min_length' => 100,
            'priority' => 1.9,
        ],

        // 📱 PATTERN RESPONSIVE E MOBILE (PRIORITÀ MEDIA)
        [
            'name' => 'mobile_content',
            'regex' => '/<div[^>]*class="[^"]*(?:visible-xs|d-block d-sm-none)[^"]*"[^>]*>(.*?)<\/div>/is',
            'description' => 'Contenuto specifico mobile',
            'min_length' => 20,
            'priority' => 2.8,
        ],
        [
            'name' => 'desktop_content',
            'regex' => '/<div[^>]*class="[^"]*(?:hidden-xs|d-none d-sm-block)[^"]*"[^>]*>(.*?)<\/div>/is',
            'description' => 'Contenuto specifico desktop',
            'min_length' => 30,
            'priority' => 2.9,
        ],

        // 📄 PATTERN DOCUMENTI E ALLEGATI (PRIORITÀ MEDIA)
        [
            'name' => 'document_links',
            'regex' => '/<a[^>]*href="[^"]*\.(?:pdf|doc|docx|xls|xlsx|ppt|pptx)"[^>]*>(.*?)<\/a>/is',
            'description' => 'Link a documenti scaricabili',
            'min_length' => 5,
            'priority' => 2.6,
        ],
        [
            'name' => 'download_content',
            'regex' => '/<div[^>]*class="[^"]*(?:download|attachment|allegat)[^"]*"[^>]*>(.*?)<\/div>/is',
            'description' => 'Sezioni download e allegati',
            'min_length' => 30,
            'priority' => 2.7,
        ],

        // 📊 PATTERN TABELLE E DATI STRUTTURATI (PRIORITÀ ALTA)
        [
            'name' => 'table_caption',
            'regex' => '/<caption[^>]*>(.*?)<\/caption>/is',
            'description' => 'Didascalie tabelle',
            'min_length' => 10,
            'priority' => 1.8,
        ],
        [
            'name' => 'table_headers',
            'regex' => '/<th[^>]*>(.*?)<\/th>/is',
            'description' => 'Intestazioni tabelle',
            'min_length' => 3,
            'priority' => 2.2,
        ],
        [
            'name' => 'data_table_content',
            'regex' => '/<table[^>]*class="[^"]*(?:data|striped|table)[^"]*"[^>]*>(.*?)<\/table>/is',
            'description' => 'Contenuto tabelle dati',
            'min_length' => 100,
            'priority' => 2.0,
        ],

        // 🗞️ PATTERN NEWS E BLOG (PRIORITÀ ALTA)
        [
            'name' => 'blog_post_content',
            'regex' => '/<div[^>]*class="[^"]*(?:post-content|blog-content)[^"]*"[^>]*>(.*?)<\/div>/is',
            'description' => 'Contenuto post blog',
            'min_length' => 200,
            'priority' => 1.8,
        ],
        [
            'name' => 'news_article_body',
            'regex' => '/<div[^>]*class="[^"]*(?:article-body|news-body)[^"]*"[^>]*>(.*?)<\/div>/is',
            'description' => 'Corpo articoli news',
            'min_length' => 150,
            'priority' => 1.7,
        ],
        [
            'name' => 'content_summary',
            'regex' => '/<div[^>]*class="[^"]*(?:summary|excerpt|intro)[^"]*"[^>]*>(.*?)<\/div>/is',
            'description' => 'Riassunti e introduzioni',
            'min_length' => 50,
            'priority' => 2.1,
        ],

        // Pattern 2: Container di contenuto standard
        [
            'name' => 'content_main_text',
            'regex' => '/<div[^>]*class="[^"]*(?:content-main|main-content|content-text)[^"]*"[^>]*>(.*?)<\/div>/is',
            'description' => 'Contenitore contenuto principale',
            'min_length' => 100,
            'priority' => 3,
        ],
        [
            'name' => 'article_body',
            'regex' => '/<div[^>]*class="[^"]*(?:article-body|post-content|entry-content)[^"]*"[^>]*>(.*?)<\/div>/is',
            'description' => 'Corpo articolo/post',
            'min_length' => 100,
            'priority' => 4,
        ],

        // Pattern 3: Elementi HTML5 semantici
        [
            'name' => 'main_semantic',
            'regex' => '/<main[^>]*>(.*?)<\/main>/is',
            'description' => 'Elemento HTML5 main',
            'min_length' => 100,
            'priority' => 5,
        ],
        [
            'name' => 'article_semantic',
            'regex' => '/<article[^>]*>(.*?)<\/article>/is',
            'description' => 'Elemento HTML5 article',
            'min_length' => 100,
            'priority' => 6,
        ],

        // Pattern 4: CMS specifici
        [
            'name' => 'wordpress_content',
            'regex' => '/<div[^>]*class="[^"]*(?:entry-content|post-content|content)[^"]*"[^>]*>(.*?)<\/div>/is',
            'description' => 'Contenuto WordPress',
            'min_length' => 100,
            'priority' => 7,
        ],
        [
            'name' => 'drupal_content',
            'regex' => '/<div[^>]*class="[^"]*(?:field-type-text|node-content|content)[^"]*"[^>]*>(.*?)<\/div>/is',
            'description' => 'Contenuto Drupal',
            'min_length' => 100,
            'priority' => 8,
        ],
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
        'node-content',
        // Angular/SPA indicators
        'app-latest-article',
        'app-treelist',
        'card-body',
        'role="main"',
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
            'navigation', 'breadcrumb', 'pagination', 'social', 'share',
        ],
        'preserve_elements' => [
            'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li',
            'strong', 'em', 'a', 'blockquote',
        ],
    ],
];
