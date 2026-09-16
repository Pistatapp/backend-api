<?php

namespace App\Exceptions;

use RuntimeException;

class CropRequiredForLoadEstimation extends RuntimeException
{
    public const ERROR_CODE = 'CROP_REQUIRED_FOR_LOAD_ESTIMATION';

    public function __construct()
    {
        parent::__construct('لطفاً ابتدا از بخش ویرایش اطلاعات باغ، نوع محصول باغ را تعیین نمایید.');
    }
}
