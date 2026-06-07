<?php

namespace App\Contracts;

interface WeatherProvider
{
    public function current(string $location): array;

    public function forecast(string $location, int $days): array;

    public function future(string $location, string $date): array;

    public function history(string $location, string $startDt, string $endDt): array;
}
