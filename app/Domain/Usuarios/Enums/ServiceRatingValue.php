<?php

namespace App\Domain\Usuarios\Enums;

enum ServiceRatingValue: string
{
    case Good = 'buena';
    case Neutral = 'regular';
    case Bad = 'mala';
}
