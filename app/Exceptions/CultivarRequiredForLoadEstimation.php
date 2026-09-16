<?php

namespace App\Exceptions;

use RuntimeException;

class CultivarRequiredForLoadEstimation extends RuntimeException
{
    public const ERROR_CODE = 'CULTIVAR_REQUIRED_FOR_LOAD_ESTIMATION';

    public function __construct()
    {
        parent::__construct('برای پیش‌بینی بار، رقم محصول قطعه مشخص نشده است.');
    }
}
