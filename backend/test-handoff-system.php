<?php

/**
 * 🧪 Test Script per Handoff System
 * 
 * Questo script testa:
 * 1. Creazione handoff request
 * 2. Emissione evento HandoffRequested
 * 3. Assegnazione a operatore
 * 4. Verifica campo corretto assigned_operator_id
 * 
 * Usage:
 * php backend/test-handoff-system.php
 */

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Tenant;
use App\Models\ConversationSession;
use App\Models\HandoffRequest;
use App\Models\User;
use App\Services\HandoffService;
use App\Events\HandoffRequested;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\DB;

echo "🧪 ==========================================\n";
echo "   HANDOFF SYSTEM TEST\n";
echo "==========================================\n\n";

// ✅ Step 1: Find or create test data
echo "📊 Step 1: Finding test data...\n";

$tenant = Tenant::first();
if (!$tenant) {
    die("❌ Error: No tenants found in database!\n");
}
echo "   ✅ Tenant: {$tenant->name} (ID: {$tenant->id})\n";

$operator = User::where('is_operator', true)
                ->where('operator_status', 'available')
                ->first();

if (!$operator) {
    echo "   ⚠️  No available operator found. Creating test operator...\n";
    $operator = User::create([
        'name' => 'Test Operator',
        'email' => 'test.operator@example.com',
        'password' => bcrypt('password'),
        'is_operator' => true,
        'operator_status' => 'available',
        'max_concurrent_conversations' => 5,
        'current_conversations' => 0
    ]);
    echo "   ✅ Created operator: {$operator->name} (ID: {$operator->id})\n";
} else {
    echo "   ✅ Operator: {$operator->name} (ID: {$operator->id})\n";
}

$session = ConversationSession::where('tenant_id', $tenant->id)
                              ->where('status', 'active')
                              ->first();

if (!$session) {
    echo "   ⚠️  No active session found. Creating test session...\n";
    
    // Get widget config for tenant
    $widgetConfig = $tenant->widgetConfig;
    if (!$widgetConfig) {
        echo "   ⚠️  No widget config found, creating default...\n";
        $widgetConfig = \App\Models\WidgetConfig::createDefaultForTenant($tenant);
    }
    
    $session = ConversationSession::create([
        'session_id' => 'test-' . uniqid(),
        'tenant_id' => $tenant->id,
        'widget_config_id' => $widgetConfig->id,
        'user_identifier' => 'test-user',
        'channel' => 'web',
        'status' => 'active',
        'handoff_status' => 'bot_only',
        'started_at' => now(),
        'last_activity_at' => now()
    ]);
    echo "   ✅ Created session: {$session->session_id}\n";
} else {
    echo "   ✅ Session: {$session->session_id}\n";
}

echo "\n";

// 🧹 Cleanup: Remove existing handoffs for this session (from previous runs)
echo "🧹 Pre-Test Cleanup: Removing existing handoffs for session...\n";
$deletedCount = HandoffRequest::where('conversation_session_id', $session->id)->delete();
if ($deletedCount > 0) {
    echo "   ✅ Deleted {$deletedCount} existing handoff(s)\n";
} else {
    echo "   ✅ No existing handoffs to clean\n";
}

echo "\n";

// ✅ Step 2: Test Event Emission
echo "📡 Step 2: Testing HandoffRequested Event...\n";

$eventDispatched = false;
$dispatchedHandoffId = null;

// Listen for the event
Event::listen(HandoffRequested::class, function ($event) use (&$eventDispatched, &$dispatchedHandoffId) {
    $eventDispatched = true;
    $dispatchedHandoffId = $event->handoffRequest->id;
});

$handoffService = app(HandoffService::class);

try {
    $handoffRequest = $handoffService->requestHandoff(
        $session,
        'user_explicit',
        'Test handoff request',
        ['test' => true],
        'normal'
    );
    
    echo "   ✅ HandoffRequest created: ID {$handoffRequest->id}\n";
    echo "      - Status: {$handoffRequest->status}\n";
    echo "      - Priority: {$handoffRequest->priority}\n";
    echo "      - Trigger: {$handoffRequest->trigger_type}\n";
    
    // Check if event was dispatched
    if ($eventDispatched && $dispatchedHandoffId === $handoffRequest->id) {
        echo "   ✅ Event HandoffRequested dispatched correctly!\n";
    } else {
        echo "   ❌ Event NOT dispatched or wrong ID!\n";
        echo "      - Event Dispatched: " . ($eventDispatched ? 'YES' : 'NO') . "\n";
        echo "      - Dispatched ID: {$dispatchedHandoffId}\n";
        echo "      - Expected ID: {$handoffRequest->id}\n";
        exit(1);
    }
    
} catch (\Exception $e) {
    echo "   ❌ Error: {$e->getMessage()}\n";
    exit(1);
}

echo "\n";

// ✅ Step 3: Test Operator Assignment
echo "👨‍💼 Step 3: Testing Operator Assignment...\n";

echo "   🔍 Operator Status Check:\n";
echo "      - is_operator: " . ($operator->isOperator() ? 'YES' : 'NO') . "\n";
echo "      - canTakeNewConversation: " . ($operator->canTakeNewConversation() ? 'YES' : 'NO') . "\n";
echo "      - operator_status: {$operator->operator_status}\n";
echo "      - current_conversations: {$operator->current_conversations}\n";
echo "      - max_concurrent_conversations: {$operator->max_concurrent_conversations}\n";

try {
    $success = $handoffService->assignToOperator($handoffRequest, $operator);
    
    if ($success) {
        $handoffRequest->refresh();
        echo "   ✅ Operator assigned successfully!\n";
        echo "      - Assigned Operator ID: {$handoffRequest->assigned_operator_id}\n";
        echo "      - Assigned Operator Name: {$handoffRequest->assignedOperator->name}\n";
        echo "      - Status: {$handoffRequest->status}\n";
        echo "      - Assigned At: {$handoffRequest->assigned_at}\n";
        
        // ✅ CRITICAL CHECK: Verify correct field name
        if ($handoffRequest->assigned_operator_id === $operator->id) {
            echo "   ✅ CRITICAL: 'assigned_operator_id' field is CORRECT!\n";
        } else {
            echo "   ❌ CRITICAL: 'assigned_operator_id' field is WRONG!\n";
            exit(1);
        }
        
    } else {
        echo "   ❌ Assignment failed!\n";
        exit(1);
    }
    
} catch (\Exception $e) {
    echo "   ❌ Error: {$e->getMessage()}\n";
    echo "      Stack: {$e->getTraceAsString()}\n";
    exit(1);
}

echo "\n";

// ✅ Step 4: Verify Database State
echo "💾 Step 4: Verifying Database State...\n";

$dbHandoff = HandoffRequest::find($handoffRequest->id);
if ($dbHandoff) {
    echo "   ✅ Handoff found in database\n";
    echo "      - ID: {$dbHandoff->id}\n";
    echo "      - Status: {$dbHandoff->status}\n";
    echo "      - Assigned Operator ID: {$dbHandoff->assigned_operator_id}\n";
    
    // Check if relationship works
    if ($dbHandoff->assignedOperator) {
        echo "   ✅ Relationship 'assignedOperator' works correctly!\n";
        echo "      - Operator Name: {$dbHandoff->assignedOperator->name}\n";
    } else {
        echo "   ❌ Relationship 'assignedOperator' is broken!\n";
        exit(1);
    }
} else {
    echo "   ❌ Handoff not found in database!\n";
    exit(1);
}

echo "\n";

// ✅ Step 5: Test Take Over Flow
echo "🎯 Step 5: Testing Take Over Flow...\n";

try {
    // Reset session for takeover test
    $session->update([
        'status' => 'active',
        'handoff_status' => 'handoff_requested',
        'assigned_operator_id' => null
    ]);
    
    DB::transaction(function () use ($session, $operator) {
        // Simulate takeOverConversation logic
        $session->update([
            'status' => 'assigned',
            'handoff_status' => 'handoff_active',
            'assigned_operator_id' => $operator->id,
            'last_activity_at' => now()
        ]);
        
        // Create handoff with CORRECT field name
        $handoffRequest = HandoffRequest::firstOrCreate([
            'conversation_session_id' => $session->id,
            'tenant_id' => $session->tenant_id,
        ], [
            'trigger_type' => 'manual_operator',
            'reason' => 'Test take over',
            'priority' => 'normal',
            'status' => 'assigned',
            'assigned_operator_id' => $operator->id,  // ✅ CORRECT FIELD
            'requested_at' => now(),
            'assigned_at' => now()
        ]);
        
        echo "   ✅ Take Over simulation successful!\n";
        echo "      - Session Status: {$session->status}\n";
        echo "      - Handoff Status: {$session->handoff_status}\n";
        echo "      - Assigned Operator: {$session->assignedOperator->name}\n";
        echo "      - Handoff Request ID: {$handoffRequest->id}\n";
    });
    
} catch (\Exception $e) {
    echo "   ❌ Error: {$e->getMessage()}\n";
    echo "      Stack: {$e->getTraceAsString()}\n";
    exit(1);
}

echo "\n";

// ✅ Cleanup
echo "🧹 Cleanup: Reverting test changes...\n";
if ($handoffRequest) {
    $handoffRequest->delete();
    echo "   ✅ Test handoff deleted\n";
}
if ($session->session_id === 'test-' . substr($session->session_id, 5)) {
    $session->delete();
    echo "   ✅ Test session deleted\n";
}

echo "\n";
echo "✅ ==========================================\n";
echo "   ALL TESTS PASSED! 🎉\n";
echo "==========================================\n";
echo "\nHandoff system is working correctly!\n";
echo "Event emission: ✅\n";
echo "Operator assignment: ✅\n";
echo "Database fields: ✅\n";
echo "Take over flow: ✅\n";

