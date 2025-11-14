# Scraper – Estrazione titolo e profondità

## Titolo originale (`source_page_title`)

- Durante lo scraping ogni pagina HTML viene analizzata per leggere il `<title>` (con fallback su `<h1>`).
- Il valore normalizzato viene salvato nella colonna `documents.source_page_title`.
- I worker paralleli (`ScrapeUrlJob`) ora passano esplicitamente questo campo a `saveAndIngestSingleResult`, garantendo che i documenti creati/aggiornati lo memorizzino.
- Se il titolo è assente nel markup l’informazione rimane `null`; in UI mostriamo `N/A`.

## Profondità massima

- La profondità configurabile è `scraper_configs.max_depth` (default 2).  
- `scrapeRecursiveParallel()` propaga il parametro `depth` ai job figli; gli URL vengono messi in coda solo se `depth + 1 <= max_depth`.
- A ogni enqueuing viene scritto un log `📥 [PARALLEL-SCRAPE] Queuing child URL` con `parent_url`, `child_url` e `next_depth`.

## Log utili

- `🔍 [FETCH-DEBUG]` include lunghezza contenuto e depth dell’URL in lavorazione.
- `📥 [PARALLEL-SCRAPE] Queuing child URL` aiuta a verificare che non superiamo la profondità massima.
- File: `storage/logs/scraper-YYYY-MM-DD.log`.

## Test automatici

- `tests/Unit/Services/Scraper/WebScraperTitleExtractionTest.php` verifica che il titolo salvato non sia perso nella pipeline parallela.
- `tests/Feature/Scraper/ScraperParallelDepthTest.php` assicura che non vengano dispatchati job oltre `max_depth`.


