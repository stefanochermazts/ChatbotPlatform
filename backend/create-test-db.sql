-- Script SQL per creare database di test
-- Esegui tramite Laragon Database Manager o pgAdmin

DROP DATABASE IF EXISTS chatbot_test;
CREATE DATABASE chatbot_test 
    WITH ENCODING 'UTF8'
    OWNER postgres;

-- Nessuna estensione pgvector necessaria!
-- Milvus gestisce tutti i vettori

