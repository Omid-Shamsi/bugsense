<?php

namespace App\Enums;

enum BugRelationshipType: string
{
    case DuplicateOf = 'duplicate_of';
    case RelatedTo = 'related_to';
    case Blocks = 'blocks';
}
