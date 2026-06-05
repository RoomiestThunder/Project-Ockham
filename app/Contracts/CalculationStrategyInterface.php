<?php

namespace App\Contracts;

use App\DTOs\CalculationInputDTO;
use App\DTOs\CalculationResultDTO;

/**
 * Calculation strategy interface
 *
 * Unifies synchronous and asynchronous calculation execution.
 * Both strategies share the same business logic
 * but differ in execution method and persistence.
 */
interface CalculationStrategyInterface
{
    /**
     * Execute the calculation
     *
     * @param CalculationInputDTO $input Input data for the calculation
     * @param callable|null $progressCallback Optional callback for tracking progress
     * @return CalculationResultDTO Calculation result
     */
    public function execute(CalculationInputDTO $input, ?callable $progressCallback = null): CalculationResultDTO;

    /**
     * Check whether the strategy supports persistence to the database
     *
     * @return bool
     */
    public function shouldPersist(): bool;

    /**
     * Check whether the strategy uses caching
     *
     * @return bool
     */
    public function shouldCache(): bool;

    /**
     * Get the strategy name for logging
     *
     * @return string
     */
    public function getName(): string;
}
