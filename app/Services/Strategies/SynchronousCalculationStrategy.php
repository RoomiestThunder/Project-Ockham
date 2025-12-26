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
 * Синхронная стратегия расчета (Fixed/Interactive Mode)
 * 
 * Используется для интерактивных расчетов (UI слайдеры).
 * - Выполняется в контексте HTTP-запроса
 * - НЕ пишет в БД
 * - Кэширует результаты в Redis (TTL 1 час)
 * - Возвращает результат немедленно
 */
class SynchronousCalculationStrategy implements CalculationStrategyInterface
{
    private const CACHE_TTL = 3600; // 1 час

    public function __construct(
        private readonly CalculatorService $calculator
    ) {
    }

    public function execute(CalculationInputDTO $input, ?callable $progressCallback = null): CalculationResultDTO
    {
        // Проверяем кэш
        $cacheKey = $this->getCacheKey($input);
        $cached = Redis::get($cacheKey);

        if ($cached !== null) {
            Log::info('Calculation retrieved from cache', [
                'cache_key' => $cacheKey,
                'case_id' => $input->caseId,
            ]);

            return unserialize($cached);
        }

        // Выполняем расчет
        Log::info('Executing synchronous calculation', [
            'case_id' => $input->caseId,
        ]);

        $result = $this->calculator->calculate($input, $progressCallback);

        // Кэшируем результат
        Redis::setex($cacheKey, self::CACHE_TTL, serialize($result));

        Log::info('Calculation completed and cached', [
            'cache_key' => $cacheKey,
            'execution_time' => $result->executionTimeSeconds,
        ]);

        return $result;
    }

    public function shouldPersist(): bool
    {
        return false; // Синхронный режим НЕ пишет в БД
    }

    public function shouldCache(): bool
    {
        return true; // Синхронный режим использует Redis кэш
    }

    public function getName(): string
    {
        return 'synchronous';
    }

    /**
     * Генерация ключа кэша
     */
    private function getCacheKey(CalculationInputDTO $input): string
    {
        $hashId = app(HashGeneratorService::class)->generateForCalculation($input);
        return "calc:sync:{$hashId}";
    }

    /**
     * Инвалидировать кэш для данного расчета
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
