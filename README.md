# ChatbotPlatform

Piattaforma SaaS multitenant per chatbot con RAG avanzato, API compatibili OpenAI Chat Completions e UI TALL.

## Sviluppo rapido (Windows/Laragon)

1. cd backend
2. cp .env.example .env && php artisan key:generate
3. Configura DB Postgres/Redis in .env
4. php artisan migrate --seed
5. php artisan storage:link
6. php artisan serve

API docs: vedi +'docs/api.md+'.
