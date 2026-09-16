<?php

namespace App\Exceptions;

use RuntimeException;

class CultivarProductMismatchForLoadEstimation extends RuntimeException
{
    public const ERROR_CODE = 'CULTIVAR_PRODUCT_MISMATCH_FOR_LOAD_ESTIMATION';

    public function __construct()
    {
        parent::__construct('رقم انتخاب‌شده با محصول این باغ سازگار نیست.');
    }
}
