<?php

namespace App\Enums;

enum BugStatus: string
{
    case Submitted = 'submitted';
    case Review = 'review';
    case Assigned = 'assigned';
    case InProgress = 'in_progress';
    case Resolved = 'resolved';
    case QaVerification = 'qa_verification';
    case Closed = 'closed';
    case NeedsInformation = 'needs_information';
    case Reopened = 'reopened';
}
