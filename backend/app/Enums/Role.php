<?php

namespace App\Enums;

enum Role: string
{
    case Reporter = 'reporter';
    case Developer = 'developer';
    case QA = 'qa';
    case Admin = 'admin';
}
