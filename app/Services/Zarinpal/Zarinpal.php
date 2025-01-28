<?php

namespace App\Services\Zarinpal;

class Zarinpal {
    /**
     * Initiate a new payment request.
     *
     * @return Request
     */
    public function request()
    {
        return new Request();
    }

    /**
     * Verify the payment status.
     *
     * @return Verification
     */
    public function verify()
    {
        return new Verification();
    }
}
