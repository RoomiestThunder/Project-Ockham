<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\CalculationController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Здесь определяются маршруты для API расчетов Project Ockham.
| Все маршруты автоматически получают префикс /api.
|
*/

Route::prefix('v1')->middleware(['api'])->group(function () {
    
    // === Расчеты ===
    
    // Выполнить расчет (Sync или Async)
    Route::post('/cases/{caseId}/calculate', [CalculationController::class, 'calculate'])
        ->name('api.calculations.calculate');
    
    // Получить статус расчета
    Route::get('/calculations/{calculationId}/status', [CalculationController::class, 'status'])
        ->name('api.calculations.status');
    
    // Получить результаты расчета
    Route::get('/calculations/{calculationId}/results', [CalculationController::class, 'results'])
        ->name('api.calculations.results');
    
    // Инвалидировать кэш
    Route::delete('/cases/{caseId}/cache', [CalculationController::class, 'invalidateCache'])
        ->name('api.calculations.invalidate-cache');
    
    // Отменить расчет
    Route::post('/calculations/{calculationId}/cancel', [CalculationController::class, 'cancel'])
        ->name('api.calculations.cancel');
});

/*
|--------------------------------------------------------------------------
| Примеры запросов
|--------------------------------------------------------------------------
|
| 1. Интерактивный расчет (Sync):
|    POST /api/v1/cases/123/calculate
|    {
|      "is_interactive": true,
|      "engineer_params": { ... },
|      "production_params": { ... },
|      ...
|    }
|
| 2. Monte Carlo расчет (Async):
|    POST /api/v1/cases/123/calculate
|    {
|      "is_interactive": false,
|      "iterations": 1000,
|      "engineer_params": { ... },
|      ...
|    }
|
| 3. Проверить статус:
|    GET /api/v1/calculations/{id}/status
|
| 4. Получить результаты:
|    GET /api/v1/calculations/{id}/results
|
*/
