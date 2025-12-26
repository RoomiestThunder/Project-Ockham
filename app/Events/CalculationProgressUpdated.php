<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Событие обновления прогресса расчета
 * 
 * Транслируется через WebSocket для real-time обновления UI
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
     * Канал для broadcasting
     */
    public function broadcastOn(): Channel
    {
        return new Channel("case.{$this->caseId}.calculations");
    }

    /**
     * Имя события
     */
    public function broadcastAs(): string
    {
        return 'calculation.progress';
    }

    /**
     * Данные для broadcast
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
