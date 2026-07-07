<?php

namespace App;

enum MedicineStatus: string
{
    case ACTIVE = 'active';
    case EXPIRED = 'expired';
    case FINISHED = 'finished';
    case DISPOSED = 'disposed';
}
