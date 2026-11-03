<?php

namespace App\Support;

class TimeDuration
{
    public static function format(int $minutes): string
    {
        return ($minutes < 0 ? '-' : '').intdiv(abs($minutes), 60).' h '.str_pad((string) (abs($minutes) % 60), 2, '0', STR_PAD_LEFT).' min';
    }
}
