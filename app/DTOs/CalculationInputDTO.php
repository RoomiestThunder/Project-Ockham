<?php

namespace App\DTOs;

/**
 * DTO for calculation input data
 *
 * Contains all parameters required to perform a calculation.
 * Used for Hash ID generation via DataCanonicalizer.
 */
class CalculationInputDTO
{
    public function __construct(
        public readonly int $caseId,
        public readonly string $calculationType, // 'fixed' or 'monte_carlo'
        public readonly array $engineerParams,    // Engineering parameters
        public readonly array $productionParams,  // Production parameters
        public readonly array $salesParams,       // Sales parameters
        public readonly array $capexParams,       // CAPEX
        public readonly array $opexParams,        // OPEX
        public readonly array $taxParams,         // Taxes
        public readonly ?int $iterations = null,  // Monte Carlo: number of iterations
        public readonly ?array $metadata = null,  // Additional metadata
    ) {
    }

    /**
     * Convert to array for hashing
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'case_id' => $this->caseId,
            'calculation_type' => $this->calculationType,
            'engineer_params' => $this->engineerParams,
            'production_params' => $this->productionParams,
            'sales_params' => $this->salesParams,
            'capex_params' => $this->capexParams,
            'opex_params' => $this->opexParams,
            'tax_params' => $this->taxParams,
            'iterations' => $this->iterations,
            // metadata is excluded from hashing
        ];
    }

    /**
     * Check whether the calculation is interactive (synchronous)
     *
     * @return bool
     */
    public function isInteractive(): bool
    {
        return $this->calculationType === 'fixed';
    }

    /**
     * Check whether the calculation is Monte Carlo (asynchronous)
     *
     * @return bool
     */
    public function isMonteCarlo(): bool
    {
        return $this->calculationType === 'monte_carlo';
    }
}
