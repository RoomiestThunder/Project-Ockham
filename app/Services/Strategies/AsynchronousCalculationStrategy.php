<?php

namespace App\Services\Strategies;

use App\Contracts\CalculationStrategyInterface;
use App\DTOs\CalculationInputDTO;
use App\DTOs\CalculationResultDTO;
use App\Jobs\RunMonteCarloCalculation;
use App\Models\Calculation;
use Illuminate\Support\Facades\Log;

/**
 * Asynchronous calculation strategy (Monte Carlo mode)
 *
 * Used for probabilistic calculations (1000+ iterations).
 * - Runs via Laravel Queue
 * - Writes results to MySQL
 * - Supports progress tracking via WebSocket
 * - Returns a job ID for status polling
 */
class AsynchronousCalculationStrategy implements CalculationStrategyInterface
{
    public function execute(CalculationInputDTO $input, ?callable $progressCallback = null): CalculationResultDTO
    {
        Log::info('Dispatching asynchronous calculation', [
            'case_id' => $input->caseId,
            'iterations' => $input->iterations,
        ]);

        // Create a database record with status "pending"
        $calculation = Calculation::create([
            'case_id' => $input->caseId,
            'hash_id' => app(\App\Services\HashGeneratorService::class)->generateForCalculation($input),
            'calculation_type' => $input->calculationType,
            'status' => 'pending',
            'input_params' => $input->toArray(),
            'iterations_total' => $input->iterations,
            'iterations_completed' => 0,
            'started_at' => now(),
        ]);

        // Dispatch job to the queue
        RunMonteCarloCalculation::dispatch($calculation->id, $input)
            ->onQueue('calculations') // Dedicated queue for calculations
            ->delay(now()->addSeconds(1)); // Small delay for UI responsiveness

        Log::info('Calculation job dispatched', [
            'calculation_id' => $calculation->id,
            'job_queue' => 'calculations',
        ]);

        // Return a temporary result with job metadata
        return new CalculationResultDTO(
            hashId: $calculation->hash_id,
            engineerResults: [],
            productionResults: [],
            salesResults: [],
            capexResults: [],
            opexResults: [],
            taxResults: [],
            finalMetrics: [
                'status' => 'pending',
                'calculation_id' => $calculation->id,
                'message' => 'Calculation queued for processing',
            ],
            distributions: null,
            iterationsCompleted: 0,
            executionTimeSeconds: 0.0,
        );
    }

    public function shouldPersist(): bool
    {
        return true; // Async mode writes to the database
    }

    public function shouldCache(): bool
    {
        return false; // Async mode does not use cache (uses the database instead)
    }

    public function getName(): string
    {
        return 'asynchronous';
    }
}
