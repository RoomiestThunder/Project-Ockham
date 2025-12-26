<?php

namespace App\Services;

use App\DTOs\CalculationInputDTO;
use Illuminate\Support\Facades\Log;

/**
 * Сервис генерации стабильных Hash ID для расчетов
 * 
 * Использует DataCanonicalizer для обеспечения детерминированности хэшей.
 * Гарантирует, что одинаковые входные данные всегда дают одинаковый Hash ID.
 */
class HashGeneratorService
{
    public function __construct(
        private readonly DataCanonicalizer $canonicalizer
    ) {
    }

    /**
     * Сгенерировать Hash ID для расчета
     * 
     * @param CalculationInputDTO $input
     * @return string
     */
    public function generateForCalculation(CalculationInputDTO $input): string
    {
        $data = $input->toArray();
        
        // Логируем для отладки (можно отключить в продакшене)
        if (config('app.debug')) {
            Log::debug('Generating hash for calculation', [
                'case_id' => $input->caseId,
                'calculation_type' => $input->calculationType,
            ]);
        }

        return $this->canonicalizer->generateHash($data);
    }

    /**
     * Сгенерировать короткий Hash ID (первые 16 символов)
     * 
     * Используется для человекочитаемых ID в UI
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
     * Проверить, совпадают ли Hash ID для двух наборов входных данных
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
     * Валидировать Hash ID
     * 
     * @param string $hashId
     * @return bool
     */
    public function isValidHash(string $hashId): bool
    {
        return strlen($hashId) === 64 && ctype_xdigit($hashId);
    }
}
