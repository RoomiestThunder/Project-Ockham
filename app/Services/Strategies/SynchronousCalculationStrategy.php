<?php

namespace App\Services\Strategies;

use App\Contracts\CalculationStrategyInterface;
use App\DTOs\CalculationInputDTO;
use App\DTOs\CalculationResultDTO;
use App\Services\CalculatorService;
use App\Services\HashGeneratorService;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;

/**
 * Synchronous calculation strategy (Fixed/Interactive mode)
 *
 * Used for interactive calculations (UI sliders).
 * - Executes within the HTTP request context
 * - Does NOT write to the database
 * - Caches results in Redis (TTL 1 hour)
 * - Returns the result immediately
 */
class SynchronousCalculationStrategy implements CalculationStrategyInterface
{
    private const CACHE_TTL = 3600; // 1 hour

    public function __construct(
        private readonly CalculatorService $calculator
    ) {
    }

    public function execute(CalculationInputDTO $input, ?callable $progressCallback = null): CalculationResultDTO
    {
        // Check cache
        $cacheKey = $this->getCacheKey($input);
        $cached = Redis::get($cacheKey);

        if ($cached !== null) {
            Log::info('Calculation retrieved from cache', [
                'cache_key' => $cacheKey,
                'case_id' => $input->caseId,
            ]);

            return unserialize($cached);
        }

        // Execute calculation
        Log::info('Executing synchronous calculation', [
            'case_id' => $input->caseId,
        ]);

        $result = $this->calculator->calculate($input, $progressCallback);

        // Cache the result
        Redis::setex($cacheKey, self::CACHE_TTL, serialize($result));

        Log::info('Calculation completed and cached', [
            'cache_key' => $cacheKey,
            'execution_time' => $result->executionTimeSeconds,
        ]);

        return $result;
    }

    public function shouldPersist(): bool
    {
        return false; // Sync mode does NOT write to the database
    }

    public function shouldCache(): bool
    {
        return true; // Sync mode uses Redis cache
    }

    public function getName(): string
    {
        return 'synchronous';
    }

    /**
     * Generate cache key
     */
    private function getCacheKey(CalculationInputDTO $input): string
    {
        $hashId = app(HashGeneratorService::class)->generateForCalculation($input);
        return "calc:sync:{$hashId}";
    }

    /**
     * Invalidate the cache for a given calculation
     */
    public function invalidateCache(CalculationInputDTO $input): void
    {
        $cacheKey = $this->getCacheKey($input);
        Redis::del($cacheKey);

        Log::info('Cache invalidated', [
            'cache_key' => $cacheKey,
        ]);
    }
}
