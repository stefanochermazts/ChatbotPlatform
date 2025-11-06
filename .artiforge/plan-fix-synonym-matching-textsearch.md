# Piano di Sviluppo: Fix Synonym Matching in TextSearchService

**Task**: Fix problema matching sinonimi quando il nome espanso contiene termini che non matchano direttamente nel chunk

**Problema Identificato**: 
- Query "telefono vigili urbani" non trova risultati anche se nel chunk c'è "polizia locale" (sinonimo)
- Il nome viene espanso correttamente a "vigili urbani polizia locale municipale"
- MA TextSearchService cerca solo i primi 2 termini ("vigili" e "urbani") nel chunk
- Se nel chunk c'è solo "polizia locale", non matcha

**Soluzione Implementata**:
1. Metodo helper `buildSynonymMatchingConditions()` che genera condizioni SQL OR per matchare qualsiasi combinazione di termini sinonimi
2. Aggiornati tutti i metodi find*NearName per usare il nuovo matching
3. Migliorato anche il matching PHP per considerare tutti i termini nel calcolo della posizione nome

**File Modificati**:
- `backend/app/Services/RAG/TextSearchService.php`

**Metodi Aggiornati**:
- `findPhonesNearName()` ✅
- `findEmailsNearName()` ✅
- `findAddressesNearName()` ✅
- `findSchedulesNearName()` ✅
- `buildSynonymMatchingConditions()` (nuovo helper) ✅

**Test**: Tenant 5, query "telefono vigili urbani" deve trovare risultati quando nel chunk c'è "polizia locale"

