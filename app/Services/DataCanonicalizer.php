<?php

namespace App\Services;

/**
 * Сервис канонизации данных для стабильного хэширования
 * 
 * Решает проблему нестабильности хэшей из-за:
 * - Разного порядка ключей в JSON
 * - Различий в точности float-значений
 * - Непоследовательной обработки null-значений
 * 
 * Гарантирует, что одинаковые данные всегда дают одинаковый hash.
 */
class DataCanonicalizer
{
    /**
     * Точность для округления float-значений
     */
    private const FLOAT_PRECISION = 10;

    /**
     * Канонизировать данные для хэширования
     * 
     * @param mixed $data Исходные данные
     * @return string Канонизированная строка для хэширования
     */
    public function canonicalize(mixed $data): string
    {
        $normalized = $this->normalize($data);
        return json_encode($normalized, JSON_THROW_ON_ERROR);
    }

    /**
     * Нормализовать данные рекурсивно
     * 
     * @param mixed $data
     * @return mixed
     */
    private function normalize(mixed $data): mixed
    {
        // Обработка null
        if ($data === null) {
            return null;
        }

        // Обработка boolean
        if (is_bool($data)) {
            return $data;
        }

        // Обработка float с нормализацией точности
        if (is_float($data)) {
            return $this->normalizeFloat($data);
        }

        // Обработка целых чисел
        if (is_int($data)) {
            return $data;
        }

        // Обработка строк
        if (is_string($data)) {
            return trim($data);
        }

        // Обработка массивов
        if (is_array($data)) {
            return $this->normalizeArray($data);
        }

        // Обработка объектов (конвертация в массив)
        if (is_object($data)) {
            return $this->normalizeArray((array) $data);
        }

        return $data;
    }

    /**
     * Нормализовать float с фиксированной точностью
     * 
     * Решает проблему: 0.1 + 0.2 !== 0.3 в PHP
     * 
     * @param float $value
     * @return float
     */
    private function normalizeFloat(float $value): float
    {
        // Обработка специальных значений
        if (is_nan($value)) {
            return 0.0;
        }

        if (is_infinite($value)) {
            return $value > 0 ? PHP_FLOAT_MAX : -PHP_FLOAT_MAX;
        }

        // Округление до фиксированной точности
        return round($value, self::FLOAT_PRECISION);
    }

    /**
     * Нормализовать массив с сортировкой ключей
     * 
     * @param array $array
     * @return array
     */
    private function normalizeArray(array $array): array
    {
        // Проверка, является ли массив ассоциативным
        $isAssociative = $this->isAssociativeArray($array);

        if ($isAssociative) {
            // Удаляем null-значения (опционально, зависит от требований)
            // $array = array_filter($array, fn($value) => $value !== null);

            // Сортируем ключи для детерминированного порядка
            ksort($array);
        }

        // Рекурсивно нормализуем значения
        return array_map(fn($value) => $this->normalize($value), $array);
    }

    /**
     * Проверить, является ли массив ассоциативным
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
     * Сгенерировать Hash ID из данных
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
     * Проверить, совпадают ли два набора данных после канонизации
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
