# Piano: Debug Intent `schedule`

## Step 1 – Reproduci il bug
- Verificare su branch principale con configurazione prod
- Eseguire query per orari e catturare log/output

## Step 2 – Localizza codice intent
- Trovare handler per `schedule` e confrontarlo con `phone`/`email`

## Step 3 – Confronto implementazioni
- Analizzare differenze: estrazione parametri, libreria di retrieval, validazioni

## Step 4 – Risolvere issue
- Applicare fix mantenendo stile e controlli (timezone, date future, ecc.)

## Step 5 – Test integrazione intent
- Aggiungere test end-to-end per scheduling (successo/errore)

## Step 6 – Documentazione
- Aggiornare docs con comportamento corretto del `schedule`

## Step 7 – Test suite + verifica manuale
- Eseguire test completi e proof manuale in staging

## Step 8 – PR & merge
- Branch dedicato, commit ordinati, code review, merge
