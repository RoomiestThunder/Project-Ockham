<?php

namespace App\Contracts;

use App\DTOs\CalculationInputDTO;
use App\DTOs\CalculationResultDTO;

/**
 * Интерфейс стратегии расчета
 * 
 * Унифицирует синхронное и асинхронное выполнение расчетов.
 * Обе стратегии используют одну и ту же бизнес-логику,
 * но отличаются способом выполнения и персистентностью.
 */
interface CalculationStrategyInterface
{
    /**
     * Выполнить расчет
     * 
     * @param CalculationInputDTO $input Входные данные для расчета
     * @param callable|null $progressCallback Коллбэк для отслеживания прогресса (опционально)
     * @return CalculationResultDTO Результат расчета
     */
    public function execute(CalculationInputDTO $input, ?callable $progressCallback = null): CalculationResultDTO;

    /**
     * Проверить, поддерживает ли стратегия персистентность в БД
     * 
     * @return bool
     */
    public function shouldPersist(): bool;

    /**
     * Проверить, использует ли стратегия кэширование
     * 
     * @return bool
     */
    public function shouldCache(): bool;

    /**
     * Получить имя стратегии для логирования
     * 
     * @return string
     */
    public function getName(): string;
}
