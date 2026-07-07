<?php

namespace App;

enum MedicineBoxUserRole: string
{
    case OWNER = 'owner';
    case EDITOR = 'editor';
    case VIEWER = 'viewer';

}
