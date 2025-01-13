<?php

namespace App\Services\Zarinpal;

class Request
{
    /**
     * @property \SoapClient $client The SOAP client used to communicate with the Zarinpal API.
     */
    protected $client;

    /**
     * @property string $merchantId The merchant ID provided by Zarinpal.
     */
    protected $merchantId;

    /**
     * @property int $amount The amount to be paid.
     */
    protected $amount;

    /**
     * @property string $description The description of the payment.
     */
    protected $description;

    /**
     * @property string $callback The callback URL to be called after the payment.
     */
    protected $callback;

    /**
     * @property string $email The email address of the payer.
     */
    protected $email;

    /**
     * @property string $mobile The mobile number of the payer.
     */
    protected $mobile;

    public function __construct()
    {
        $this->client = new \SoapClient('https://www.zarinpal.com/pg/services/WebGate/wsdl', ['encoding' => 'UTF-8']);
    }

    /**
     * Send request to Zarinpal API.
     *
     * @return mixed
     */
    public function send()
    {
        $result = $this->client->PaymentRequest(
            [
                'MerchantID' => config('services.zarinpal.merchant_id'),
                'Amount' => $this->amount,
                'Description' => $this->description,
                'Email' => $this->email,
                'Mobile' => $this->mobile,
                'CallbackURL' => $this->callback,
            ]
        );

        return new RequestResponse($result->Status, $result->Authority);
    }

    /**
     * Set the amount of the transaction
     *
     * @param $amount
     * @return $this
     */
    public function amount($amount)
    {
        $this->amount = $amount;

        return $this;
    }

    /**
     * Set the description of the transaction
     *
     * @param $description
     * @return $this
     */
    public function description($description)
    {
        $this->description = $description;

        return $this;
    }

    /**
     * Set the callback URL
     *
     * @param $callback
     * @return $this
     */
    public function callback($callback)
    {
        $this->callback = $callback;

        return $this;
    }

    /**
     * Set the email of the user
     *
     * @param $email
     * @return $this
     */
    public function email($email)
    {
        $this->email = $email;

        return $this;
    }

    /**
     * Set the mobile number of the user
     *
     * @param $mobile
     * @return $this
     */
    public function mobile($mobile)
    {
        $this->mobile = $mobile;

        return $this;
    }
}
