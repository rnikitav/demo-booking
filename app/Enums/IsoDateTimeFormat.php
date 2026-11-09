<?php

namespace App\Enums;

/**
 * Допустимые форматы ISO 8601: смещение обязательно, секунды — по желанию.
 * Правило date_format сверяет строку с форматированной датой, поэтому
 * нужны оба варианта смещения: P печатает «+00:00», p — «Z».
 */
enum IsoDateTimeFormat: string
{
    case WithSecondsAndOffset = 'Y-m-d\TH:i:sP';
    case WithSecondsAndZulu = 'Y-m-d\TH:i:sp';
    case WithOffset = 'Y-m-d\TH:iP';
    case WithZulu = 'Y-m-d\TH:ip';

    /**
     * Правило валидации со всеми форматами: date_format:Y-m-d\TH:i:sP,...
     */
    public static function validationRule(): string
    {
        return 'date_format:'.implode(',', array_column(self::cases(), 'value'));
    }
}
