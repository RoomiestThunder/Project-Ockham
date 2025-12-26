<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Событие ошибки расчета
 */
class CalculationFailed implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int $calculationId,
        public readonly int $caseId,
        public readonly string $error,
    ) {
    }

    public function broadcastOn(): Channel
    {
        return new Channel("case.{$this->caseId}.calculations");
    }

    public function broadcastAs(): string
    {
        return 'calculation.failed';
    }

    public function broadcastWith(): array
    {
        return [
            'calculation_id' => $this->calculationId,
            'error' => $this->error,
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
