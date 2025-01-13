<?php

namespace App\Services\Zarinpal;

class VerificationResponse
{
    public function __construct(
        protected int $status,
        protected string $refId
    ) {}

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
     * Get the refId
     *
     * @return string
     */
    public function refId(): string
    {
        return $this->refId;
    }

    /**
     * Get the error message
     *
     * @return string
     */
    public function error(): string
    {
        return (new Error($this->status))->message();
    }
}
