<?php

namespace App\DTOs;

/**
 * DTO for a calculation result
 *
 * Contains all computed metrics and intermediate data.
 * Structure is identical for Sync and Async modes.
 */
class CalculationResultDTO
{
    public function __construct(
        public readonly string $hashId,           // Unique Hash ID of the calculation
        public readonly array $engineerResults,   // Engineering calculation results
        public readonly array $productionResults, // Production calculation results
        public readonly array $salesResults,      // Sales calculation results
        public readonly array $capexResults,      // CAPEX results
        public readonly array $opexResults,       // OPEX results
        public readonly array $taxResults,        // Tax calculation results
        public readonly array $finalMetrics,      // Final metrics (NPV, IRR, PI, etc.)
        public readonly ?array $distributions = null, // Monte Carlo: result distributions
        public readonly ?int $iterationsCompleted = null, // Monte Carlo: iterations completed
        public readonly float $executionTimeSeconds = 0.0, // Execution time
    ) {
    }

    /**
     * Convert to array for serialization
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'hash_id' => $this->hashId,
            'engineer_results' => $this->engineerResults,
            'production_results' => $this->productionResults,
            'sales_results' => $this->salesResults,
            'capex_results' => $this->capexResults,
            'opex_results' => $this->opexResults,
            'tax_results' => $this->taxResults,
            'final_metrics' => $this->finalMetrics,
            'distributions' => $this->distributions,
            'iterations_completed' => $this->iterationsCompleted,
            'execution_time_seconds' => $this->executionTimeSeconds,
        ];
    }

    /**
     * Get key metrics for quick access
     *
     * @return array
     */
    public function getKeyMetrics(): array
    {
        return [
            'npv' => $this->finalMetrics['npv'] ?? null,
            'irr' => $this->finalMetrics['irr'] ?? null,
            'pi' => $this->finalMetrics['pi'] ?? null,
            'payback_period' => $this->finalMetrics['payback_period'] ?? null,
        ];
    }
}
