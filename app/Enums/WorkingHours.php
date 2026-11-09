<?php

namespace App\Enums;

/**
 * Границы рабочего дня (час) по местному времени офиса.
 */
enum WorkingHours: int
{
    case Start = 9;
    case End = 20;
}
