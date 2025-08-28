SELECT 
    dc.document_id,
    dc.chunk_index,
    (dc.document_id * 100000 + dc.chunk_index) as primary_id,
    length(dc.content) as content_length,
    substring(dc.content, 1, 200) as content_preview,
    CASE 
        WHEN dc.content ILIKE '%polizia%' OR dc.content ILIKE '%comando%' THEN 'CONTIENE_POLIZIA' 
        ELSE 'NO_POLIZIA' 
    END as has_polizia
FROM document_chunks dc 
JOIN documents d ON dc.document_id = d.id 
WHERE d.tenant_id = 5 
    AND d.title ILIKE '%San Cesareo%'
ORDER BY dc.chunk_index;
