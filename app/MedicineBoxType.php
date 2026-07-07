<?php

namespace App;

enum MedicineBoxType: string
{
    case HOME = 'home';
    case CAR = 'car';
    case CHILD = 'child';
    case TRAVEL = 'travel';
    case CUSTOM = 'custom';
}
