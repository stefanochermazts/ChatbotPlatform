<?php

namespace App\Events;

use App\Models\ConversationMessage;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ConversationMessageSent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public ConversationMessage $message
    ) {}

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            // 🎯 Channel privato per la sessione specifica
            new PrivateChannel('conversation.' . $this->message->conversationSession->session_id),
            // 👨‍💼 Channel per operatori del tenant
            new PrivateChannel('tenant.' . $this->message->tenant_id . '.operators'),
        ];
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'message' => [
                'id' => $this->message->id,
                'content' => $this->message->content,
                'content_type' => $this->message->content_type,
                'sender_type' => $this->message->sender_type,
                'sender_name' => $this->message->getDisplayName(),
                'citations' => $this->message->citations,
                'sent_at' => $this->message->sent_at->toISOString(),
                'is_helpful' => $this->message->is_helpful
            ],
            'session' => [
                'session_id' => $this->message->conversationSession->session_id,
                'status' => $this->message->conversationSession->status,
                'handoff_status' => $this->message->conversationSession->handoff_status
            ]
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'message.sent';
    }
}
