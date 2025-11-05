-- Script per creare il database di test PostgreSQL
-- NON serve pgvector extension (usiamo Milvus per embeddings)
-- Esegui con: psql -U postgres < setup-test-db.sql
-- Oppure crea manualmente da Laragon > Database > PostgreSQL > Create Database

-- Drop se esiste (per reset pulito)
DROP DATABASE IF EXISTS chatbot_test;

-- Crea database
CREATE DATABASE chatbot_test 
    WITH ENCODING 'UTF8';

-- Connetti al database (opzionale)
-- \c chatbot_test

-- NO pgvector extension needed (Milvus handles all vector ops)
-- CREATE EXTENSION IF NOT EXISTS pg_trgm;  -- Opzionale per similarity()

-- Verifica
-- SELECT version();

GRANT ALL PRIVILEGES ON DATABASE chatbot_test TO postgres;

