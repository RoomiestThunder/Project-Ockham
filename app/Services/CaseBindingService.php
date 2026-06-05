<?php

namespace App\Services;

use App\DTOs\CalculationInputDTO;
use App\Models\Calculation;
use App\Models\CaseModel;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Smart binding service for linking calculations to cases
 *
 * Implements the following logic:
 * 1. Look up existing calculations by Hash ID (deduplication)
 * 2. Bind calculations to a case via last_calculation_id
 * 3. Grace period before detaching old calculations (7 days)
 * 4. Automatic cleanup of stale records
 */
class CaseBindingService
{
    /**
     * Grace period before detachment (days)
     */
    private const GRACE_PERIOD_DAYS = 7;

    /**
     * Period until physical deletion after detachment (days)
     */
    private const DELETE_AFTER_DAYS = 30;

    public function __construct(
        private readonly HashGeneratorService $hashGenerator
    ) {
    }

    /**
     * Find an existing completed calculation by input data
     *
     * Uses Hash ID to look up identical calculations.
     * Returns null if nothing is found.
     *
     * @param CalculationInputDTO $input
     * @return Calculation|null
     */
    public function findExistingCalculation(CalculationInputDTO $input): ?Calculation
    {
        $hashId = $this->hashGenerator->generateForCalculation($input);

        // Look for a completed calculation with the same Hash ID
        $calculation = Calculation::query()
            ->where('hash_id', $hashId)
            ->where('status', 'completed')
            ->whereNull('delete_at') // Not marked for deletion
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
     * Bind a calculation to a case
     *
     * Updates last_calculation_id on the case.
     * If a previous calculation exists, schedules its detachment with a grace period.
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

            // If a previous calculation exists, schedule its detachment
            if ($case->last_calculation_id !== null && $case->last_calculation_id !== $calculationId) {
                $this->scheduleDetachment($case->last_calculation_id);
            }

            // Bind the new calculation
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
     * Schedule detachment of a calculation (with grace period)
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
     * Run cleanup of old calculations
     *
     * Should be called via cron (e.g. once per day).
     * Deletes calculations whose delete_at date has passed.
     *
     * @return int Number of deleted records
     */
    public function cleanupOldCalculations(): int
    {
        $now = Carbon::now();

        // Find calculations due for deletion
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
     * Cancel a scheduled deletion for a calculation
     *
     * Used when a calculation has become relevant again.
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
     * Get calculation statistics for a case
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
