<?php

namespace App;

enum MedicineStatus: string
{
    case ACTIVE = 'active';
    case EXPIRING = 'expiring';
    case EXPIRED = 'expired';
    case FINISHED = 'finished';
    case DISPOSED = 'disposed';
}
