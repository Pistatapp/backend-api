<?php

namespace App\Services\Zarinpal;

class Zarinpal {
    protected $amount;
    protected $description;
    protected $email;
    protected $mobile;
    protected $callback;

    public function request()
    {
        return new Request();
    }

    public function verify()
    {
        return new Verification();
    }
}
