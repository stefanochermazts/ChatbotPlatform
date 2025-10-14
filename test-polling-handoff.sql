-- 🧪 Test Polling Handoff System
-- Verifica se ci sono handoff pending che il polling dovrebbe trovare

SELECT 
    '🔍 HANDOFF PENDING (quello che il polling cerca):' as "Test",
    COUNT(*) as "Count"
FROM handoff_requests
WHERE status = 'pending'
  AND assigned_operator_id IS NULL;

SELECT 
    '📊 ULTIMI 5 HANDOFF (tutti):' as "Test",
    id,
    status,
    assigned_operator_id as "assigned_to",
    tenant_id,
    created_at
FROM handoff_requests
ORDER BY id DESC
LIMIT 5;

SELECT 
    '⏰ HANDOFF PENDING DETAILS:' as "Test",
    hr.id,
    hr.status,
    hr.priority,
    hr.reason,
    hr.tenant_id,
    t.name as "tenant_name",
    cs.session_id,
    hr.requested_at
FROM handoff_requests hr
LEFT JOIN tenants t ON hr.tenant_id = t.id
LEFT JOIN conversation_sessions cs ON hr.conversation_session_id = cs.id
WHERE hr.status = 'pending'
  AND hr.assigned_operator_id IS NULL
ORDER BY hr.requested_at DESC
LIMIT 3;

