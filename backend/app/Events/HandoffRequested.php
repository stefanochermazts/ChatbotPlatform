<?php

namespace App\Events;

use App\Models\HandoffRequest;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class HandoffRequested implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public HandoffRequest $handoffRequest
    ) {}

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            // 👨‍💼 Channel per tutti gli operatori del tenant
            new PrivateChannel('tenant.' . $this->handoffRequest->tenant_id . '.operators'),
            // 🚨 Channel priorità alta per handoff urgenti
            new PrivateChannel('handoffs.urgent'),
        ];
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'handoff_request' => [
                'id' => $this->handoffRequest->id,
                'priority' => $this->handoffRequest->priority,
                'trigger_type' => $this->handoffRequest->trigger_type,
                'reason' => $this->handoffRequest->reason,
                'requested_at' => $this->handoffRequest->requested_at->toISOString(),
                'age_minutes' => $this->handoffRequest->getAgeInMinutes()
            ],
            'session' => [
                'session_id' => $this->handoffRequest->conversationSession->session_id,
                'user_identifier' => $this->handoffRequest->conversationSession->user_identifier,
                'channel' => $this->handoffRequest->conversationSession->channel,
                'last_activity_at' => $this->handoffRequest->conversationSession->last_activity_at->toISOString()
            ],
            'tenant_id' => $this->handoffRequest->tenant_id
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'handoff.requested';
    }

    /**
     * Determine if this event should broadcast based on conditions.
     */
    public function broadcastWhen(): bool
    {
        // 📢 Broadcast solo se handoff è in pending
        return $this->handoffRequest->status === 'pending';
    }
}
