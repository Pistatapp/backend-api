<?php

namespace App\Services\Zarinpal;

use App\Services\Zarinpal\VerificationResponse;

class Verification
{
    /**
     * @property \SoapClient $client The SOAP client used for communication with the Zarinpal API.
     */
    protected $client;

    /**
     * @property string $authority The authority code received from Zarinpal.
     */
    protected $authority;

    /**
     * @property int $amount The amount to be verified.
     */
    protected $amount;

    public function __construct()
    {
        $this->client = new \SoapClient('https://www.zarinpal.com/pg/services/WebGate/wsdl', ['encoding' => 'UTF-8']);
    }

    /**
     * Sends a payment verification request to the Zarinpal API.
     *
     * @return VerificationResponse The response from the Zarinpal API containing the status and reference ID.
     */
    public function send()
    {
        $result = $this->client->PaymentVerification(
            [
                'MerchantID' => config('services.zarinpal.merchant_id'),
                'Authority' => $this->authority,
                'Amount' => $this->amount,
            ]
        );

        return new VerificationResponse($result->Status, $result->RefId);
    }

    /**
     * Sets the authority code for the payment verification.
     *
     * @param string $authority The authority code received from the payment request.
     * @return self
     */
    public function authority(string $authority): self
    {
        $this->authority = $authority;

        return $this;
    }

    /**
     * Sets the amount for the payment verification.
     *
     * @param int $amount The amount to be verified.
     * @return self
     */
    public function amount(int $amount): self
    {
        $this->amount = $amount;

        return $this;
    }
}
