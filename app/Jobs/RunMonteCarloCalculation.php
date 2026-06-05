<?php

namespace App\Jobs;

use App\DTOs\CalculationInputDTO;
use App\Models\Calculation;
use App\Services\CalculatorService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Job for asynchronous execution of Monte Carlo calculations
 *
 * Features:
 * - Runs via Laravel Queue (Redis/Database)
 * - Supports progress tracking via Redis
 * - Broadcasts progress over WebSockets
 * - Handles errors and retry logic
 * - Persists results to the database
 */
class RunMonteCarloCalculation implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Number of execution attempts on failure
     */
    public int $tries = 3;

    /**
     * Execution timeout (seconds)
     */
    public int $timeout = 3600; // 1 hour for large Monte Carlo runs

    /**
     * Delay between retries (seconds)
     */
    public int $backoff = 60;

    public function __construct(
        private readonly int $calculationId,
        private readonly CalculationInputDTO $input,
    ) {
    }

    /**
     * Execute the job
     */
    public function handle(CalculatorService $calculator): void
    {
        $calculation = Calculation::findOrFail($this->calculationId);

        try {
            Log::info('Starting Monte Carlo calculation job', [
                'calculation_id' => $this->calculationId,
                'case_id' => $this->input->caseId,
                'iterations' => $this->input->iterations,
            ]);

            // Update status
            $calculation->update([
                'status' => 'processing',
                'started_at' => now(),
            ]);

            // Send initial progress
            $this->updateProgress($calculation, 0, 'Initializing calculation');

            // Execute calculation with progress callback
            $result = $calculator->calculate(
                $this->input,
                fn($percentage, $message) => $this->updateProgress($calculation, $percentage, $message)
            );

            // Persist results to the database
            $this->saveResults($calculation, $result);

            Log::info('Monte Carlo calculation completed', [
                'calculation_id' => $this->calculationId,
                'execution_time' => $result->executionTimeSeconds,
                'iterations' => $result->iterationsCompleted,
            ]);

        } catch (Throwable $e) {
            $this->handleFailure($calculation, $e);
            throw $e; // Re-throw for the retry mechanism
        }
    }

    /**
     * Update progress in Redis and broadcast via WebSocket
     */
    private function updateProgress(Calculation $calculation, int $percentage, string $message): void
    {
        // Update progress in Redis (for fast access)
        $progressKey = "calc:progress:{$calculation->id}";
        $progressData = [
            'calculation_id' => $calculation->id,
            'case_id' => $this->input->caseId,
            'percentage' => $percentage,
            'message' => $message,
            'timestamp' => now()->timestamp,
        ];

        Redis::setex($progressKey, 300, json_encode($progressData)); // TTL 5 minutes

        // Update percentage in the database (every 5%)
        if ($percentage % 5 === 0 || $percentage === 100) {
            $calculation->update([
                'progress_percentage' => $percentage,
                'progress_message' => $message,
            ]);
        }

        // Broadcast progress via WebSocket (Laravel Broadcasting)
        // Assumes Laravel Echo + Pusher/Soketi is configured
        broadcast(new \App\Events\CalculationProgressUpdated(
            calculationId: $calculation->id,
            caseId: $this->input->caseId,
            percentage: $percentage,
            message: $message,
        ))->toOthers();

        Log::debug('Progress updated', [
            'calculation_id' => $calculation->id,
            'percentage' => $percentage,
            'message' => $message,
        ]);
    }

    /**
     * Persist calculation results to the database
     */
    private function saveResults(Calculation $calculation, $result): void
    {
        DB::transaction(function () use ($calculation, $result) {
            $calculation->update([
                'status' => 'completed',
                'progress_percentage' => 100,
                'progress_message' => 'Calculation completed',
                'completed_at' => now(),
                'iterations_completed' => $result->iterationsCompleted,
                'execution_time_seconds' => $result->executionTimeSeconds,
                
                // Save all results into JSON columns
                'engineer_results' => $result->engineerResults,
                'production_results' => $result->productionResults,
                'sales_results' => $result->salesResults,
                'capex_results' => $result->capexResults,
                'opex_results' => $result->opexResults,
                'tax_results' => $result->taxResults,
                'final_metrics' => $result->finalMetrics,
                'distributions' => $result->distributions,
            ]);

            // Update last_job_id on the case for Smart Binding
            DB::table('cases')
                ->where('id', $this->input->caseId)
                ->update([
                    'last_calculation_id' => $calculation->id,
                    'last_calculation_hash' => $result->hashId,
                    'updated_at' => now(),
                ]);
        });

        // Dispatch the final completion event
        broadcast(new \App\Events\CalculationCompleted(
            calculationId: $calculation->id,
            caseId: $this->input->caseId,
            results: $result->getKeyMetrics(),
        ))->toOthers();

        // Clear progress from Redis
        Redis::del("calc:progress:{$calculation->id}");
    }

    /**
     * Handle an execution error
     */
    private function handleFailure(Calculation $calculation, Throwable $e): void
    {
        Log::error('Monte Carlo calculation failed', [
            'calculation_id' => $calculation->id,
            'case_id' => $this->input->caseId,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);

        $calculation->update([
            'status' => 'failed',
            'progress_message' => 'Error: ' . $e->getMessage(),
            'failed_at' => now(),
            'error_message' => $e->getMessage(),
            'error_trace' => $e->getTraceAsString(),
        ]);

        // Dispatch the failure event
        broadcast(new \App\Events\CalculationFailed(
            calculationId: $calculation->id,
            caseId: $this->input->caseId,
            error: $e->getMessage(),
        ))->toOthers();

        // Clear progress from Redis
        Redis::del("calc:progress:{$calculation->id}");
    }

    /**
     * Hook called on final failure (after all retries are exhausted)
     */
    public function failed(Throwable $exception): void
    {
        $calculation = Calculation::find($this->calculationId);
        
        if ($calculation) {
            $calculation->update([
                'status' => 'permanently_failed',
                'progress_message' => 'Calculation failed after multiple attempts',
                'failed_at' => now(),
            ]);
        }

        Log::critical('Monte Carlo calculation permanently failed', [
            'calculation_id' => $this->calculationId,
            'exception' => $exception->getMessage(),
        ]);
    }

    /**
     * Get tags for Laravel Horizon
     */
    public function tags(): array
    {
        return [
            'calculation',
            'monte_carlo',
            "case:{$this->input->caseId}",
            "calc:{$this->calculationId}",
        ];
    }
}
