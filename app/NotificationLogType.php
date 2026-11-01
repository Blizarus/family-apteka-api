<?php

namespace App;

enum NotificationLogType: string
{
    case EXPIRY_WARNING_30D = 'expiry_warning_30d';
    case EXPIRY_WARNING_7D = 'expiry_warning_7d';
    case EXPIRY_CRITICAL = 'expiry_critical';
    case INTAKE_REMINDER = 'intake_reminder';
    case OPENED_EXPIRED = 'opened_expired';
    case LOW_STOCK = 'low_stock';
    case PUSH_TEST = 'push_test';
}
