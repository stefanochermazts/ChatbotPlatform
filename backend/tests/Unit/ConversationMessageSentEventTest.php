<?php

namespace Tests\Unit;

use App\Events\ConversationMessageSent;
use App\Models\ConversationMessage;
use App\Models\ConversationSession;
use App\Models\Tenant;
use App\Models\WidgetConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ConversationMessageSentEventTest extends TestCase
{
    use RefreshDatabase;

    public function test_broadcast_payload_contains_expected_fields(): void
    {
        $tenant = Tenant::factory()->create();
        $widget = WidgetConfig::createDefaultForTenant($tenant);

        $session = ConversationSession::create([
            'tenant_id' => $tenant->id,
            'widget_config_id' => $widget->id,
            'session_id' => (string) Str::uuid(),
            'channel' => 'widget',
            'started_at' => now(),
            'last_activity_at' => now(),
            'status' => 'active',
            'handoff_status' => 'bot_only'
        ]);

        $message = ConversationMessage::create([
            'conversation_session_id' => $session->id,
            'tenant_id' => $tenant->id,
            'sender_type' => 'system',
            'content' => '🤖 Sono tornato!',
            'content_type' => 'text',
            'sent_at' => now(),
            'delivered_at' => now()
        ]);

        $event = new ConversationMessageSent($message);

        $payload = $event->broadcastWith();

        $this->assertArrayHasKey('message', $payload);
        $this->assertSame('system', $payload['message']['sender_type']);
        $this->assertSame('🤖 Sono tornato!', $payload['message']['content']);
        $this->assertArrayHasKey('session', $payload);
        $this->assertSame($session->session_id, $payload['session']['session_id']);
    }
}



