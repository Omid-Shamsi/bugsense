<?php

namespace App\Enums;

enum QAVerificationDecision: string
{
    case Approved = 'approved';
    case Rejected = 'rejected';
}
