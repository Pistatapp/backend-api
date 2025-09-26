<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\App;

class WarningService
{
    private array $warningDefinitions;

    public function __construct()
    {
        $this->warningDefinitions = json_decode(
            Storage::get('json/warnings.json'),
            true
        );
    }

    public function getWarningsByRelatedTo(string $relatedTo): array
    {
        return collect($this->warningDefinitions)
            ->filter(fn ($warning) => $warning['related-to'] === $relatedTo)
            ->toArray();
    }

    public function getWarningDefinition(string $key): ?array
    {
        return $this->warningDefinitions[$key] ?? null;
    }

    public function validateParameters(string $key, array $parameters): bool
    {
        if (!isset($this->warningDefinitions[$key])) {
            return false;
        }

        $requiredParams = $this->warningDefinitions[$key]['setting-message-parameters'];
        return empty(array_diff($requiredParams, array_keys($parameters)));
    }

    public function formatWarningMessage(string $key, array $parameters): string
    {
        if (!isset($this->warningDefinitions[$key])) {
            return '';
        }

        // Try to get translated message first
        $translationKey = "warnings.{$key}";
        $message = __($translationKey);

        // If translation doesn't exist, fall back to original message
        if ($message === $translationKey) {
            $message = $this->warningDefinitions[$key]['warning-message'];
        }

        foreach ($parameters as $param => $value) {
            $message = str_replace(':' . $param, $value, $message);
        }
        return $message;
    }

    public function formatSettingMessage(string $key, array $parameters = []): string
    {
        if (!isset($this->warningDefinitions[$key])) {
            return '';
        }

        $message = $this->warningDefinitions[$key]['setting-message'];
        foreach ($parameters as $param => $value) {
            $message = str_replace(':' . $param, $value, $message);
        }
        return $message;
    }
}
