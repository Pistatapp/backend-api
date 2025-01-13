<?php

namespace App\Services\Zarinpal;

class RequestResponse
{
    /**
     * @var int $status The status code of the response.
     */
    protected $status;

    /**
     * @var string $authority The authority code of the transaction.
     */
    protected $authority;

    public function __construct($status, $authority)
    {
        $this->status = $status;
        $this->authority = $authority;
    }

    /**
     * Check if the request was successful
     *
     * @return bool
     */
    public function success(): bool
    {
        return $this->status === 100;
    }

    /**
     * Get the authority
     *
     * @return string
     */
    public function authority(): string
    {
        return $this->authority;
    }

    /**
     * Get the url
     *
     * @return string
     */
    public function url(): string
    {
        $url = 'https://www.zarinpal.com/pg/StartPay/' . $this->authority;

        if($this->success()) {
            return $url;
        }

        return (new Error($this->status))->message();
    }
}
