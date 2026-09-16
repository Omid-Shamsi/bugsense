<?php

namespace App\Enums;

enum TrackingValueKind: string
{
    case Category = 'category';
    case Priority = 'priority';
    case Severity = 'severity';
    case Tag = 'tag';
    case ResolutionLabel = 'resolution_label';
}
