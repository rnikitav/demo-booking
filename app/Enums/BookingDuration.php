<?php

namespace App\Enums;

/**
 * Ограничения длительности брони в минутах.
 *
 * Не backed enum: у Min и Step одинаковое значение 15, а значения
 * backed enum обязаны быть уникальными.
 */
enum BookingDuration
{
    case Min;
    case Max;
    case Step;

    public function minutes(): int
    {
        return match ($this) {
            self::Min => 15,
            self::Max => 240,
            self::Step => 15,
        };
    }
}
