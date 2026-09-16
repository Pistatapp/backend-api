<?php

namespace App\Exceptions;

use RuntimeException;

class UnsupportedCultivarForLoadEstimation extends RuntimeException
{
    public const ERROR_CODE = 'UNSUPPORTED_CULTIVAR_FOR_LOAD_ESTIMATION';

    public function __construct(string $code)
    {
        parent::__construct("رقم محصول با کد {$code} برای پیش‌بینی بار پشتیبانی نمی‌شود.");
    }
}
