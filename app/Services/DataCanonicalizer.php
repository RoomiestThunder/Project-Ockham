<?php

namespace App\Services;

/**
 * Data canonicalization service for stable hashing
 *
 * Solves hash instability caused by:
 * - Different key ordering in JSON
 * - Differences in float precision
 * - Inconsistent handling of null values
 *
 * Guarantees that identical data always produces the same hash.
 */
class DataCanonicalizer
{
    /**
     * Precision for rounding float values
     */
    private const FLOAT_PRECISION = 10;

    /**
     * Canonicalize data for hashing
     *
     * @param mixed $data Source data
     * @return string Canonicalized string ready for hashing
     */
    public function canonicalize(mixed $data): string
    {
        $normalized = $this->normalize($data);
        return json_encode($normalized, JSON_THROW_ON_ERROR);
    }

    /**
     * Recursively normalize data
     *
     * @param mixed $data
     * @return mixed
     */
    private function normalize(mixed $data): mixed
    {
        // Handle null
        if ($data === null) {
            return null;
        }

        // Handle boolean
        if (is_bool($data)) {
            return $data;
        }

        // Handle float with precision normalization
        if (is_float($data)) {
            return $this->normalizeFloat($data);
        }

        // Handle integers
        if (is_int($data)) {
            return $data;
        }

        // Handle strings
        if (is_string($data)) {
            return trim($data);
        }

        // Handle arrays
        if (is_array($data)) {
            return $this->normalizeArray($data);
        }

        // Handle objects (convert to array)
        if (is_object($data)) {
            return $this->normalizeArray((array) $data);
        }

        return $data;
    }

    /**
     * Normalize a float to a fixed precision
     *
     * Addresses the issue: 0.1 + 0.2 !== 0.3 in PHP
     *
     * @param float $value
     * @return float
     */
    private function normalizeFloat(float $value): float
    {
        // Handle special values
        if (is_nan($value)) {
            return 0.0;
        }

        if (is_infinite($value)) {
            return $value > 0 ? PHP_FLOAT_MAX : -PHP_FLOAT_MAX;
        }

        // Round to fixed precision
        return round($value, self::FLOAT_PRECISION);
    }

    /**
     * Normalize an array with sorted keys
     *
     * @param array $array
     * @return array
     */
    private function normalizeArray(array $array): array
    {
        // Check whether the array is associative
        $isAssociative = $this->isAssociativeArray($array);

        if ($isAssociative) {
            // Remove null values (optional, depends on requirements)
            // $array = array_filter($array, fn($value) => $value !== null);

            // Sort keys for deterministic ordering
            ksort($array);
        }

        // Recursively normalize values
        return array_map(fn($value) => $this->normalize($value), $array);
    }

    /**
     * Check whether an array is associative
     *
     * @param array $array
     * @return bool
     */
    private function isAssociativeArray(array $array): bool
    {
        if (empty($array)) {
            return false;
        }

        return array_keys($array) !== range(0, count($array) - 1);
    }

    /**
     * Generate a Hash ID from data
     *
     * @param mixed $data
     * @return string SHA-256 hash
     */
    public function generateHash(mixed $data): string
    {
        $canonical = $this->canonicalize($data);
        return hash('sha256', $canonical);
    }

    /**
     * Check whether two data sets are equal after canonicalization
     *
     * @param mixed $data1
     * @param mixed $data2
     * @return bool
     */
    public function areEqual(mixed $data1, mixed $data2): bool
    {
        return $this->canonicalize($data1) === $this->canonicalize($data2);
    }
}
