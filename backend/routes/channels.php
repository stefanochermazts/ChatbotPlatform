<?php

use App\Models\ConversationSession;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Here you may register all of the event broadcasting channels that your
| application supports. The given channel authorization callbacks are
| used to check if an authenticated user can listen to the channel.
|
*/

// í´ Private channel per conversazioni specifiche
Broadcast::channel('conversation.{sessionId}', function (User $user, string $sessionId) {
    // Permettere accesso se:
    // 1. L'utente Ã¨ un operatore 
    // 2. La sessione Ã¨ assegnata a questo operatore
    $session = ConversationSession::where('session_id', $sessionId)->first();
    
    return $user->isOperator() && 
           $session && 
           $session->assigned_operator_id === $user->id;
});

// í±¨â€í²¼ Private channel per operatori di un tenant
Broadcast::channel('tenant.{tenantId}.operators', function (User $user, int $tenantId) {
    // Solo operatori del tenant specifico
    return $user->isOperator();
});

// íº¨ Channel per handoff urgenti (tutti gli operatori)
Broadcast::channel('handoffs.urgent', function (User $user) {
    return $user->isOperator();
});
