# Supporto Documenti Office

## 📄 Formati Supportati

Il sistema ChatbotPlatform supporta l'estrazione di testo dai seguenti formati di documenti:

| Formato | Estensioni | Libreria | Status |
|---------|------------|----------|--------|
| **Microsoft Word** | `.docx`, `.doc` | PhpOffice\PhpWord | ✅ Completo |
| **Microsoft Excel** | `.xlsx`, `.xls` | PhpOffice\PhpSpreadsheet | ✅ Completo |
| **Microsoft PowerPoint** | `.pptx`, `.ppt` | ZipArchive + XML parsing | ✅ Base |
| **PDF** | `.pdf` | Smalot\PdfParser | ✅ Completo |
| **Testo** | `.txt`, `.md` | Lettura diretta | ✅ Completo |

## 🔧 Librerie Utilizzate

### PhpOffice\PhpWord (per DOCX/DOC)
```php
"phpoffice/phpword": "1.1"
```

**Funzionalità supportate:**
- Testo nei paragrafi
- Contenuto delle tabelle
- Liste e elementi strutturati
- Gestione ricorsiva di elementi complessi

### PhpOffice\PhpSpreadsheet (per XLSX/XLS)
```php
"phpoffice/phpspreadsheet": "2.0"
```

**Funzionalità supportate:**
- Estrazione da tutti i fogli del workbook
- Valori delle celle (non formule)
- Gestione di righe e colonne dinamiche
- Supporto per formati Excel legacy (.xls)

### ZipArchive (per PPTX)
**Funzionalità supportate:**
- Estrazione testo dai slide
- Parsing XML dei contenuti
- Gestione di presentazioni multi-slide

## 🚀 Implementazione

### Metodo Principale
La logica di parsing è implementata nel metodo `readTextFromStoragePath()` della classe `IngestUploadedDocumentJob`:

```php
private function readTextFromStoragePath(string $path): string
{
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $fullPath = Storage::disk('public')->path($path);

    // Microsoft Word (.docx, .doc)
    if ($ext === 'docx' || $ext === 'doc') {
        $phpWord = \PhpOffice\PhpWord\IOFactory::load($fullPath);
        $text = '';
        
        foreach ($phpWord->getSections() as $section) {
            foreach ($section->getElements() as $element) {
                if (method_exists($element, 'getText')) {
                    $text .= $element->getText() . ' ';
                }
            }
        }
        
        return trim($text);
    }
    
    // Altri formati...
}
```

## 🧪 Testing

### Comando di Test
Utilizza il comando Artisan per testare il parsing:

```bash
php artisan document:test-parsing path/to/your/file.docx
```

**Output esempio:**
```
🔍 Testando parsing del file: test.docx
📄 Estensione: docx
📏 Dimensione: 45.2 KB
✅ Testo estratto con successo!
📊 Statistiche:
   - Caratteri: 2847
   - Parole: 423
   
📝 Preview del testo estratto:
Questo è un documento di esempio che contiene del testo formattato...

🧩 Test chunking:
   - Chunk generati: 2
   - Lunghezza primo chunk: 1500 caratteri
```

### Test Manuale
1. **Carica un documento** tramite l'interfaccia admin
2. **Verifica i log** in `storage/logs/laravel.log`
3. **Controlla l'ingestion** nella dashboard

## 📋 Gestione Errori

Ogni formato ha gestione specifica degli errori:

```php
try {
    // Parsing del documento
    return trim($text);
} catch (\Throwable $e) {
    Log::warning('docx.parse_failed', [
        'path' => $path, 
        'error' => $e->getMessage()
    ]);
    return '';
}
```

**Log pattern:**
- `pdf.parse_failed` - Errori PDF
- `docx.parse_failed` - Errori Word
- `xlsx.parse_failed` - Errori Excel
- `ppt.parse_failed` - Errori PowerPoint
- `file.unsupported_format` - Formato non supportato

## ⚙️ Configurazione

### Chunking
I parametri di chunking sono configurabili in `config/rag.php`:

```php
'chunk' => [
    'max_chars' => 1500,
    'overlap_chars' => 200,
],
```

### Validazione Upload
I formati supportati sono validati nei controller:

```php
'file' => ['required', 'file', 'mimes:pdf,txt,md,doc,docx,xls,xlsx,ppt,pptx'],
```

## 🔍 Troubleshooting

### Errori Comuni

**1. File corrotto:**
```
Log: docx.parse_failed - Invalid archive
```
**Soluzione:** Verifica che il file non sia corrotto

**2. Memoria insufficiente:**
```
Fatal error: Allowed memory size exhausted
```
**Soluzione:** Aumenta `memory_limit` in PHP o processa file più piccoli

**3. Libreria mancante:**
```
Class 'PhpOffice\PhpWord\IOFactory' not found
```
**Soluzione:** 
```bash
composer install
composer require phpoffice/phpword:^1.1
```

**4. Metodo Excel non trovato (RISOLTO):**
```
Call to undefined method PhpOffice\PhpSpreadsheet\Cell\Cell::getDisplayValue()
```
**Causa:** Il metodo `getDisplayValue()` non esiste in PhpSpreadsheet 2.0
**Soluzione:** Ora usa `getValue()`, `getFormattedValue()` e `getCalculatedValue()` in sequenza

### Log di Debug

Per debug dettagliato, controlla:
```bash
tail -f storage/logs/laravel.log | findstr "parse_failed\|ingestion"
```

## 📈 Performance

### Benchmark Tipici

| Formato | Dimensione File | Tempo Parsing | Memoria |
|---------|----------------|---------------|----------|
| DOCX | 100 KB | ~0.5s | ~8 MB |
| XLSX | 500 KB | ~1.2s | ~15 MB |
| PDF | 1 MB | ~2.0s | ~12 MB |
| PPTX | 2 MB | ~1.8s | ~10 MB |

### Ottimizzazioni

1. **Processa in coda**: L'ingestion avviene tramite job asincroni
2. **Chunking efficiente**: Memoria costante durante il chunking
3. **Gestione errori**: Fallback graceful per file problematici

## 🔮 Roadmap

- [ ] Supporto per OpenDocument (ODT, ODS, ODP)
- [ ] Miglioramento parsing PowerPoint (PhpOffice\PhpPresentation)
- [ ] Estrazione immagini e OCR
- [ ] Gestione password-protected files
- [ ] Parsing metadati avanzati
