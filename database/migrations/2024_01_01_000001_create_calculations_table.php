<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Миграция для таблицы calculations
 * 
 * Оптимизирована для:
 * - Быстрого поиска по Hash ID
 * - Эффективной очистки старых записей
 * - Связывания расчетов с Cases
 * - Хранения больших JSON-результатов
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('calculations', function (Blueprint $table) {
            $table->id();
            
            // Связь с проектом/кейсом
            $table->foreignId('case_id')
                ->constrained('cases')
                ->onDelete('cascade'); // При удалении кейса удаляются все расчеты
            
            // Hash ID для дедупликации и Smart Binding
            $table->char('hash_id', 64)->index(); // SHA-256 хэш
            
            // Тип расчета
            $table->enum('calculation_type', ['fixed', 'monte_carlo'])
                ->default('fixed');
            
            // Статус выполнения
            $table->enum('status', [
                'pending',              // В очереди
                'processing',           // Выполняется
                'completed',            // Завершен успешно
                'failed',               // Ошибка (можно retry)
                'permanently_failed',   // Окончательно провален
            ])->default('pending')->index();
            
            // Входные параметры (для воспроизводимости)
            $table->json('input_params')->nullable();
            
            // Прогресс выполнения (для Monte Carlo)
            $table->unsignedTinyInteger('progress_percentage')->default(0);
            $table->string('progress_message')->nullable();
            
            // Итерации (для Monte Carlo)
            $table->unsignedInteger('iterations_total')->nullable();
            $table->unsignedInteger('iterations_completed')->nullable();
            
            // Временные метки выполнения
            $table->timestamp('started_at')->nullable()->index();
            $table->timestamp('completed_at')->nullable()->index();
            $table->timestamp('failed_at')->nullable();
            
            // Время выполнения (секунды)
            $table->decimal('execution_time_seconds', 10, 3)->nullable();
            
            // Результаты расчетов (JSON колонки для гибкости)
            $table->json('engineer_results')->nullable();
            $table->json('production_results')->nullable();
            $table->json('sales_results')->nullable();
            $table->json('capex_results')->nullable();
            $table->json('opex_results')->nullable();
            $table->json('tax_results')->nullable();
            $table->json('final_metrics')->nullable();
            
            // Распределения (для Monte Carlo)
            $table->json('distributions')->nullable();
            
            // Информация об ошибках
            $table->text('error_message')->nullable();
            $table->text('error_trace')->nullable();
            
            // Grace Period для очистки
            // detach_at - дата, когда расчет будет отвязан от Case
            // delete_at - дата, когда расчет будет физически удален
            $table->timestamp('detach_at')->nullable()->index();
            $table->timestamp('delete_at')->nullable()->index();
            
            $table->timestamps();
            
            // === ИНДЕКСЫ ===
            
            // Составной индекс для быстрого поиска существующих расчетов
            $table->index(['case_id', 'hash_id'], 'idx_case_hash');
            
            // Индекс для поиска активных расчетов
            $table->index(['case_id', 'status'], 'idx_case_status');
            
            // Индекс для очистки старых записей
            $table->index(['delete_at', 'status'], 'idx_cleanup');
            
            // Индекс для поиска завершенных расчетов по хэшу
            $table->index(['hash_id', 'status', 'completed_at'], 'idx_hash_completed');
        });
        
        // === ТАБЛИЦА CASES (если еще не создана) ===
        
        if (!Schema::hasTable('cases')) {
            Schema::create('cases', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->text('description')->nullable();
                
                // Связь с последним расчетом (Smart Binding)
                $table->foreignId('last_calculation_id')
                    ->nullable()
                    ->constrained('calculations')
                    ->onDelete('set null');
                
                $table->char('last_calculation_hash', 64)->nullable()->index();
                
                $table->timestamps();
                $table->softDeletes(); // Мягкое удаление
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('calculations');
        Schema::dropIfExists('cases');
    }
};
