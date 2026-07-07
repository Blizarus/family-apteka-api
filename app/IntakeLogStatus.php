<?php

namespace App;

enum IntakeLogStatus: string
{
    case SCHEDULED = 'scheduled';
    case TAKEN = 'taken';
    case MISSED = 'missed';
    case SKIPPED = 'skipped';
}
