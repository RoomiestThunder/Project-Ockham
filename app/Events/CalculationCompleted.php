<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Событие успешного завершения расчета
 */
class CalculationCompleted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int $calculationId,
        public readonly int $caseId,
        public readonly array $results,
    ) {
    }

    public function broadcastOn(): Channel
    {
        return new Channel("case.{$this->caseId}.calculations");
    }

    public function broadcastAs(): string
    {
        return 'calculation.completed';
    }

    public function broadcastWith(): array
    {
        return [
            'calculation_id' => $this->calculationId,
            'results' => $this->results,
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
