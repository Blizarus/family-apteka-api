<?php

namespace App;

enum MedicineQuantityUnit: string
{
    case PILLS = 'pill';
    case ML = 'ml';
    case MG = 'mg';
    case AMPOULE = 'ampoule';
    case UNIT = 'unit';
}
