<?php

namespace App\Services;

use App\DTOs\CalculationInputDTO;
use Illuminate\Support\Facades\Log;

/**
 * Service for generating stable Hash IDs for calculations
 *
 * Uses DataCanonicalizer to ensure hash determinism.
 * Guarantees that identical input data always produces the same Hash ID.
 */
class HashGeneratorService
{
    public function __construct(
        private readonly DataCanonicalizer $canonicalizer
    ) {
    }

    /**
     * Generate a Hash ID for a calculation
     *
     * @param CalculationInputDTO $input
     * @return string
     */
    public function generateForCalculation(CalculationInputDTO $input): string
    {
        $data = $input->toArray();
        
        // Log for debugging (can be disabled in production)
        if (config('app.debug')) {
            Log::debug('Generating hash for calculation', [
                'case_id' => $input->caseId,
                'calculation_type' => $input->calculationType,
            ]);
        }

        return $this->canonicalizer->generateHash($data);
    }

    /**
     * Generate a short Hash ID (first 16 characters)
     *
     * Used for human-readable IDs in the UI
     *
     * @param CalculationInputDTO $input
     * @return string
     */
    public function generateShortHash(CalculationInputDTO $input): string
    {
        $fullHash = $this->generateForCalculation($input);
        return substr($fullHash, 0, 16);
    }

    /**
     * Check whether the Hash IDs for two sets of input data are equal
     *
     * @param CalculationInputDTO $input1
     * @param CalculationInputDTO $input2
     * @return bool
     */
    public function areInputsEqual(CalculationInputDTO $input1, CalculationInputDTO $input2): bool
    {
        return $this->generateForCalculation($input1) === $this->generateForCalculation($input2);
    }

    /**
     * Validate a Hash ID
     *
     * @param string $hashId
     * @return bool
     */
    public function isValidHash(string $hashId): bool
    {
        return strlen($hashId) === 64 && ctype_xdigit($hashId);
    }
}
