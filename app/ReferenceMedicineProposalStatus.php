<?php

namespace App;

enum ReferenceMedicineProposalStatus: string
{
    case PENDING = 'pending';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';
}
