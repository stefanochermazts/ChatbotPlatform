<?php

namespace Tests\Feature;

use App\Events\ConversationMessageSent;
use App\Models\ConversationSession;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WidgetConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

class MessageSystemSendTest extends TestCase
{
    use RefreshDatabase;

    public function test_send_system_message_to_conversation(): void
    {
        Event::fake([ConversationMessageSent::class]);

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

        $payload = [
            'session_id' => $session->session_id,
            'content' => '🤖 Sono tornato!',
            'content_type' => 'text',
            'sender_type' => 'system'
        ];

        $response = $this->postJson('/api/v1/conversations/messages/send', $payload, [
            'Accept' => 'application/json'
        ]);

        $response->assertCreated()
                 ->assertJsonPath('success', true)
                 ->assertJsonPath('message.sender_type', 'system')
                 ->assertJsonPath('message.content', '🤖 Sono tornato!');

        Event::assertNotDispatched(ConversationMessageSent::class); // API send non emette broadcast
    }
}


















