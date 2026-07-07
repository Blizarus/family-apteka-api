<?php

namespace App;

enum IntakeScheduleFrequencyType: string
{
    case DAILY = 'daily';
    case INTERVAL_HOURS = 'interval_hours';
    case SPECIFIC_DAYS  = 'specific_days';
    case AS_NEEDED = 'as_needed';

}
