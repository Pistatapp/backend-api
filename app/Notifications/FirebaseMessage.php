<?php

namespace App\Notifications;


class FirebaseMessage
{
    /**
     * The title of the notification.
     *
     * @var string
     */
    public string $title;

    /**
     * The body of the notification.
     *
     * @var string
     */
    public string $body;

    /**
     * The additional data of the notification.
     *
     * @var array
     */
    public array $data;

    public function __construct()
    {
        //
    }

    /**
     * Set the title of the notification.
     *
     * @param string $title
     * @return $this
     */
    public function title(string $title): self
    {
        $this->title = $title;

        return $this;
    }

    /**
     * Set the body of the notification.
     *
     * @param string $body
     * @return $this
     */
    public function body(string $body): self
    {
        $this->body = $body;

        return $this;
    }

    /**
     * Set the additional data of the notification.
     *
     * @param array $data
     * @return $this
     */
    public function data(array $data): self
    {
        $this->data = $data;

        return $this;
    }
}

