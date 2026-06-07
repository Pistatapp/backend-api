<?php

namespace App\Services;

use App\Contracts\WeatherProvider;
use InvalidArgumentException;

class WeatherForecastService
{
    public function __construct(
        private WeatherApi $weatherApi,
        private OpenMeteo $openMeteo,
    ) {}

    /**
     * Resolve the configured weather provider.
     */
    public function provider(): WeatherProvider
    {
        return match (config('weather-forecast.default')) {
            'weatherapi' => $this->weatherApi,
            'openmeteo' => $this->openMeteo,
            default => throw new InvalidArgumentException(
                'Unsupported weather forecast provider: ' . config('weather-forecast.default')
            ),
        };
    }

    /**
     * Fetch normalized current-day weather for the location.
     */
    public function currentDay(string $location): array
    {
        return match (config('weather-forecast.default')) {
            'weatherapi' => $this->normalizeWeatherApiCurrentDay($this->weatherApi->current($location)),
            'openmeteo' => $this->normalizeOpenMeteoCurrentDay($this->openMeteo->current($location)),
            default => throw new InvalidArgumentException(
                'Unsupported weather forecast provider: ' . config('weather-forecast.default')
            ),
        };
    }

    public function current(string $location): array
    {
        return $this->provider()->current($location);
    }

    public function forecast(string $location, int $days): array
    {
        return $this->provider()->forecast($location, $days);
    }

    public function future(string $location, string $date): array
    {
        return $this->provider()->future($location, $date);
    }

    public function history(string $location, string $startDt, string $endDt): array
    {
        return $this->provider()->history($location, $startDt, $endDt);
    }

    /**
     * Fetch normalized multi-day forecast for the location.
     *
     * @return array<int, array<string, string|null>>
     */
    public function forecastDays(string $location, int $days = 14): array
    {
        return match (config('weather-forecast.default')) {
            'weatherapi' => $this->normalizeWeatherApiForecastDays($this->weatherApi->forecast($location, $days)),
            'openmeteo' => $this->normalizeOpenMeteoDailyDays($this->openMeteo->forecast($location, $days)),
            default => throw new InvalidArgumentException(
                'Unsupported weather forecast provider: ' . config('weather-forecast.default')
            ),
        };
    }

    /**
     * Fetch normalized historical daily weather for the location.
     *
     * @return array<int, array<string, string|null>>
     */
    public function historyDays(string $location, string $startDt, string $endDt): array
    {
        return match (config('weather-forecast.default')) {
            'weatherapi' => $this->normalizeWeatherApiForecastDays($this->weatherApi->history($location, $startDt, $endDt)),
            'openmeteo' => $this->normalizeOpenMeteoDailyDays($this->openMeteo->history($location, $startDt, $endDt)),
            default => throw new InvalidArgumentException(
                'Unsupported weather forecast provider: ' . config('weather-forecast.default')
            ),
        };
    }

    private function normalizeWeatherApiForecastDays(array $data): array
    {
        $forecastDays = $data['forecast']['forecastday'] ?? [];

        return array_map(fn (array $day) => [
            'date' => jdate($day['date'])->format('Y/m/d'),
            'mintemp_c' => $this->formatNumber($day['day']['mintemp_c']),
            'avgtemp_c' => $this->formatNumber($day['day']['avgtemp_c']),
            'maxtemp_c' => $this->formatNumber($day['day']['maxtemp_c']),
            'condition' => $day['day']['condition']['text'],
            'icon' => $day['day']['condition']['icon'],
            'maxwind_kph' => $this->formatNumber($day['day']['maxwind_kph']),
            'humidity' => $this->formatNumber($day['day']['avghumidity']),
            'dewpoint_c' => $this->formatNumber(collect($day['hour'])->avg('dewpoint_c')),
            'cloud' => $this->formatNumber(collect($day['hour'])->avg('cloud')),
        ], $forecastDays);
    }

    /**
     * @return array<int, array<string, string|null>>
     */
    private function normalizeOpenMeteoDailyDays(array $data): array
    {
        $daily = $data['daily'] ?? [];
        $times = $daily['time'] ?? [];
        $hourlyAverages = $this->groupOpenMeteoHourlyAverages($data['hourly'] ?? []);

        return array_map(function (int $index) use ($daily, $hourlyAverages) {
            $date = $daily['time'][$index];
            $dateKey = substr($date, 0, 10);
            $averages = $hourlyAverages[$dateKey] ?? [];
            $weatherCode = (int) ($daily['weather_code'][$index] ?? 0);
            $minTemp = (float) ($daily['temperature_2m_min'][$index] ?? 0);
            $maxTemp = (float) ($daily['temperature_2m_max'][$index] ?? 0);
            $maxWind = $daily['wind_speed_10m_max'][$index]
                ?? $averages['wind_speed_10m_max']
                ?? null;

            return [
                'date' => jdate($date)->format('Y/m/d'),
                'mintemp_c' => $this->formatNumber($minTemp),
                'avgtemp_c' => $this->formatNumber(($minTemp + $maxTemp) / 2),
                'maxtemp_c' => $this->formatNumber($maxTemp),
                'condition' => $this->wmoWeatherDescription($weatherCode),
                'icon' => $this->wmoWeatherIcon($weatherCode),
                'maxwind_kph' => $maxWind !== null
                    ? $this->formatNumber($maxWind)
                    : null,
                'humidity' => isset($averages['relative_humidity_2m'])
                    ? $this->formatNumber($averages['relative_humidity_2m'])
                    : null,
                'dewpoint_c' => isset($averages['dewpoint_2m'])
                    ? $this->formatNumber($averages['dewpoint_2m'])
                    : null,
                'cloud' => isset($averages['cloud_cover'])
                    ? $this->formatNumber($averages['cloud_cover'])
                    : null,
            ];
        }, array_keys($times));
    }

    /**
     * @return array<string, array<string, float>>
     */
    private function groupOpenMeteoHourlyAverages(array $hourly): array
    {
        $grouped = [];

        foreach ($hourly['time'] ?? [] as $index => $time) {
            $date = substr($time, 0, 10);

            foreach (['relative_humidity_2m', 'dewpoint_2m', 'cloud_cover', 'wind_speed_10m'] as $key) {
                if (isset($hourly[$key][$index])) {
                    $grouped[$date][$key][] = (float) $hourly[$key][$index];
                }
            }
        }

        $averages = [];

        foreach ($grouped as $date => $values) {
            foreach (['relative_humidity_2m', 'dewpoint_2m', 'cloud_cover'] as $key) {
                if (! empty($values[$key])) {
                    $averages[$date][$key] = array_sum($values[$key]) / count($values[$key]);
                }
            }

            if (! empty($values['wind_speed_10m'])) {
                $averages[$date]['wind_speed_10m_max'] = max($values['wind_speed_10m']);
            }
        }

        return $averages;
    }

    private function normalizeWeatherApiCurrentDay(array $data): array
    {
        $current = $data['current'];

        return [
            'last_updated' => jdate($current['last_updated'])->format('Y/m/d H:i:s'),
            'temp_c' => $this->formatNumber($current['temp_c']),
            'condition' => $current['condition']['text'],
            'icon' => $current['condition']['icon'],
            'wind_kph' => $this->formatNumber($current['wind_kph']),
            'humidity' => $this->formatNumber($current['humidity']),
            'dewpoint_c' => $this->formatNumber($current['dewpoint_c']),
            'cloud' => $this->formatNumber($current['cloud']),
        ];
    }

    private function normalizeOpenMeteoCurrentDay(array $data): array
    {
        $current = $data['current'];
        $weatherCode = (int) ($current['weather_code'] ?? 0);

        return [
            'last_updated' => jdate($current['time'])->format('Y/m/d H:i:s'),
            'temp_c' => $this->formatNumber($current['temperature_2m']),
            'condition' => $this->wmoWeatherDescription($weatherCode),
            'icon' => $this->wmoWeatherIcon($weatherCode, (bool) ($current['is_day'] ?? true)),
            'wind_kph' => $this->formatNumber($current['wind_speed_10m']),
            'humidity' => $this->formatNumber($current['relative_humidity_2m']),
            'dewpoint_c' => isset($current['dewpoint_2m'])
                ? $this->formatNumber($current['dewpoint_2m'])
                : null,
            'cloud' => $this->formatNumber($current['cloud_cover'] ?? 0),
        ];
    }

    private function formatNumber(float|int|string|null $value): string
    {
        return number_format((float) $value, 1);
    }

    private function wmoWeatherDescription(int $code): string
    {
        return match (true) {
            $code === 0 => 'Clear sky',
            in_array($code, [1, 2, 3], true) => 'Partly cloudy',
            in_array($code, [45, 48], true) => 'Fog',
            in_array($code, [51, 53, 55], true) => 'Drizzle',
            in_array($code, [56, 57], true) => 'Freezing drizzle',
            in_array($code, [61, 63, 65], true) => 'Rain',
            in_array($code, [66, 67], true) => 'Freezing rain',
            in_array($code, [71, 73, 75], true) => 'Snow',
            in_array($code, [77], true) => 'Snow grains',
            in_array($code, [80, 81, 82], true) => 'Rain showers',
            in_array($code, [85, 86], true) => 'Snow showers',
            in_array($code, [95], true) => 'Thunderstorm',
            in_array($code, [96, 99], true) => 'Thunderstorm with hail',
            default => 'Unknown',
        };
    }

    private function wmoWeatherIcon(int $code, bool $isDay = true): string
    {
        $period = $isDay ? 'day' : 'night';
        $iconCode = match (true) {
            $code === 0 => $isDay ? 113 : 113,
            in_array($code, [1, 2], true) => $isDay ? 116 : 119,
            $code === 3 => 122,
            in_array($code, [45, 48], true) => 248,
            in_array($code, [51, 53, 55, 56, 57], true) => 266,
            in_array($code, [61, 63, 65, 66, 67, 80, 81, 82], true) => 176,
            in_array($code, [71, 73, 75, 77, 85, 86], true) => 179,
            in_array($code, [95, 96, 99], true) => 200,
            default => 113,
        };

        return "https://cdn.weatherapi.com/weather/64x64/{$period}/{$iconCode}.png";
    }
}
