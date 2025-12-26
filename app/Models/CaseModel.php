<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Модель Case (проект/кейс)
 * 
 * Представляет один проект нефтегазового моделирования.
 * Содержит связь с расчетами и последним актуальным расчетом.
 */
class CaseModel extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'cases';

    protected $fillable = [
        'name',
        'description',
        'last_calculation_id',
        'last_calculation_hash',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * Все расчеты кейса
     */
    public function calculations(): HasMany
    {
        return $this->hasMany(Calculation::class, 'case_id');
    }

    /**
     * Последний актуальный расчет
     */
    public function lastCalculation(): BelongsTo
    {
        return $this->belongsTo(Calculation::class, 'last_calculation_id');
    }

    /**
     * Активные (не помеченные на удаление) расчеты
     */
    public function activeCalculations(): HasMany
    {
        return $this->calculations()->whereNull('delete_at');
    }

    /**
     * Завершенные расчеты
     */
    public function completedCalculations(): HasMany
    {
        return $this->calculations()->where('status', 'completed');
    }
}
