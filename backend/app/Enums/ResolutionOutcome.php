<?php

namespace App\Enums;

enum ResolutionOutcome: string
{
    case Fixed = 'fixed';
    case Duplicate = 'duplicate';
    case CannotReproduce = 'cannot_reproduce';
    case WontFix = 'wont_fix';
}
