<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Event fired when calculation progress is updated
 *
 * Broadcast over WebSocket for real-time UI updates
 */
class CalculationProgressUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int $calculationId,
        public readonly int $caseId,
        public readonly int $percentage,
        public readonly string $message,
    ) {
    }

    /**
     * Broadcasting channel
     */
    public function broadcastOn(): Channel
    {
        return new Channel("case.{$this->caseId}.calculations");
    }

    /**
     * Event name
     */
    public function broadcastAs(): string
    {
        return 'calculation.progress';
    }

    /**
     * Data payload for the broadcast
     */
    public function broadcastWith(): array
    {
        return [
            'calculation_id' => $this->calculationId,
            'percentage' => $this->percentage,
            'message' => $this->message,
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
