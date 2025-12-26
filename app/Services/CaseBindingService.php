<?php

namespace App\Services;

use App\DTOs\CalculationInputDTO;
use App\Models\Calculation;
use App\Models\CaseModel;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Сервис "умной привязки" расчетов к кейсам (Smart Binding)
 * 
 * Реализует логику:
 * 1. Поиск существующих расчетов по Hash ID (дедупликация)
 * 2. Привязка расчетов к Case через last_calculation_id
 * 3. Grace Period для отвязки старых расчетов (7 дней)
 * 4. Автоматическая очистка "мусора"
 */
class CaseBindingService
{
    /**
     * Grace Period для отвязки (дни)
     */
    private const GRACE_PERIOD_DAYS = 7;

    /**
     * Период до физического удаления после отвязки (дни)
     */
    private const DELETE_AFTER_DAYS = 30;

    public function __construct(
        private readonly HashGeneratorService $hashGenerator
    ) {
    }

    /**
     * Найти существующий завершенный расчет по входным данным
     * 
     * Использует Hash ID для поиска идентичных расчетов.
     * Возвращает null, если ничего не найдено.
     * 
     * @param CalculationInputDTO $input
     * @return Calculation|null
     */
    public function findExistingCalculation(CalculationInputDTO $input): ?Calculation
    {
        $hashId = $this->hashGenerator->generateForCalculation($input);

        // Ищем завершенный расчет с таким же Hash ID
        $calculation = Calculation::query()
            ->where('hash_id', $hashId)
            ->where('status', 'completed')
            ->whereNull('delete_at') // Не помеченные на удаление
            ->latest('completed_at')
            ->first();

        if ($calculation !== null) {
            Log::info('Found existing calculation by hash', [
                'hash_id' => $hashId,
                'calculation_id' => $calculation->id,
                'completed_at' => $calculation->completed_at,
            ]);
        }

        return $calculation;
    }

    /**
     * Привязать расчет к кейсу
     * 
     * Обновляет last_calculation_id в кейсе.
     * Если есть предыдущий расчет, отвязывает его с Grace Period.
     * 
     * @param int $caseId
     * @param int $calculationId
     * @return void
     */
    public function bindCalculationToCase(int $caseId, int $calculationId): void
    {
        DB::transaction(function () use ($caseId, $calculationId) {
            $case = CaseModel::findOrFail($caseId);
            $calculation = Calculation::findOrFail($calculationId);

            // Если есть предыдущий расчет, планируем его отвязку
            if ($case->last_calculation_id !== null && $case->last_calculation_id !== $calculationId) {
                $this->scheduleDetachment($case->last_calculation_id);
            }

            // Привязываем новый расчет
            $case->update([
                'last_calculation_id' => $calculationId,
                'last_calculation_hash' => $calculation->hash_id,
            ]);

            Log::info('Calculation bound to case', [
                'case_id' => $caseId,
                'calculation_id' => $calculationId,
                'hash_id' => $calculation->hash_id,
            ]);
        });
    }

    /**
     * Запланировать отвязку расчета (с Grace Period)
     * 
     * @param int $calculationId
     * @return void
     */
    private function scheduleDetachment(int $calculationId): void
    {
        $calculation = Calculation::find($calculationId);

        if ($calculation === null) {
            return;
        }

        $detachAt = Carbon::now()->addDays(self::GRACE_PERIOD_DAYS);
        $deleteAt = $detachAt->copy()->addDays(self::DELETE_AFTER_DAYS);

        $calculation->update([
            'detach_at' => $detachAt,
            'delete_at' => $deleteAt,
        ]);

        Log::info('Calculation detachment scheduled', [
            'calculation_id' => $calculationId,
            'detach_at' => $detachAt,
            'delete_at' => $deleteAt,
        ]);
    }

    /**
     * Выполнить очистку старых расчетов
     * 
     * Должна вызываться по cron (например, раз в сутки).
     * Удаляет расчеты, у которых наступила дата delete_at.
     * 
     * @return int Количество удаленных записей
     */
    public function cleanupOldCalculations(): int
    {
        $now = Carbon::now();

        // Находим расчеты для удаления
        $calculationsToDelete = Calculation::query()
            ->whereNotNull('delete_at')
            ->where('delete_at', '<=', $now)
            ->get();

        $deletedCount = 0;

        foreach ($calculationsToDelete as $calculation) {
            Log::info('Deleting old calculation', [
                'calculation_id' => $calculation->id,
                'hash_id' => $calculation->hash_id,
                'delete_at' => $calculation->delete_at,
            ]);

            $calculation->delete();
            $deletedCount++;
        }

        if ($deletedCount > 0) {
            Log::info('Cleanup completed', [
                'deleted_count' => $deletedCount,
            ]);
        }

        return $deletedCount;
    }

    /**
     * Отменить запланированное удаление расчета
     * 
     * Используется, если расчет снова стал актуальным.
     * 
     * @param int $calculationId
     * @return void
     */
    public function cancelDeletion(int $calculationId): void
    {
        $calculation = Calculation::find($calculationId);

        if ($calculation === null) {
            return;
        }

        $calculation->update([
            'detach_at' => null,
            'delete_at' => null,
        ]);

        Log::info('Calculation deletion cancelled', [
            'calculation_id' => $calculationId,
        ]);
    }

    /**
     * Получить статистику по расчетам кейса
     * 
     * @param int $caseId
     * @return array
     */
    public function getCaseCalculationStats(int $caseId): array
    {
        $totalCalculations = Calculation::where('case_id', $caseId)->count();
        $activeCalculations = Calculation::where('case_id', $caseId)
            ->whereNull('delete_at')
            ->count();
        $completedCalculations = Calculation::where('case_id', $caseId)
            ->where('status', 'completed')
            ->count();
        $scheduledForDeletion = Calculation::where('case_id', $caseId)
            ->whereNotNull('delete_at')
            ->count();

        return [
            'total' => $totalCalculations,
            'active' => $activeCalculations,
            'completed' => $completedCalculations,
            'scheduled_for_deletion' => $scheduledForDeletion,
        ];
    }
}
