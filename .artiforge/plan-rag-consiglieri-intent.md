# Piano: Ripristinare risposta "chi sono i consiglieri" su RAG tester e widget

## Contesto
- Dopo le ultime modifiche all'intent detection, sia il RAG tester sia il widget non elencano più i consiglieri.
- Dobbiamo riprodurre il bug, analizzare le modifiche recenti, verificare l'intero flusso intent → retrieval → risposta, e implementare un fix con regressioni coperte.

## Step del piano

1. **Riprodurre il bug**
   - Avviare l'ambiente con la stessa configurazione usata in produzione.
   - Chiedere "chi sono i consiglieri" via RAG tester e widget, raccogliendo risposte e log.
   - (Opzionale) creare un test automatico che verifichi il comportamento.

2. **Analizzare i commit recenti dell'intent detection**
   - Individuare le modifiche al classificatore/intents.
   - Verificare sinonimi, pattern, soglie di confidenza relativi ai consiglieri.
   - Pianificare test unitari sul classificatore.

3. **Tracciare il codice dell'intent verso la retrieval**
   - Seguire la catena handler → servizi RAG → query DB.
   - Verificare mapping intent ↔ handler e condizioni di filtro.
   - Preparare test di integrazione per l'handler.

4. **Aggiungere logging diagnostico**
   - Loggare input, intent rilevato, confidenza, parametri di retrieval e numero di citazioni.
   - Rendere i log attivabili via livello DEBUG.

5. **Correggere eventuali problemi di mapping intent → handler**
   - Aggiornare route map / switch-case / config se necessario.
   - Gestire soglie di confidenza e fallback.
   - Validare con i test definiti.

6. **Validare la fonte dati consiglieri**
   - Controllare che le informazioni siano ancora presenti in DB/API/cache.
   - Assicurare i campi necessari per RAG.

7. **Eseguire l'intera suite di test**
   - Lanciare i test di progetto assicurandosi che i nuovi test passino.

8. **Aggiornare documentazione**
   - Documentare intent, sinonimi, soglie e configurazioni.

9. **Validare in staging**
   - Deploy in staging e verifica end-to-end.

10. **Pianificare rollout in produzione con feature flag**
   - Usare flag per rollout graduale e monitorare.

