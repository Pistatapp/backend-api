<?php

namespace App\Services;

use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Storage;

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

    public function getAllWarnings(): array
    {
        return $this->warningDefinitions;
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

        return $this->replaceParameters($message, $parameters);
    }

    public function formatSettingMessage(string $key, array $parameters = []): string
    {
        if (!isset($this->warningDefinitions[$key])) {
            return '';
        }

        // Try to get translated setting message first
        $translationKey = "warnings.settings.{$key}";
        $message = __($translationKey);

        // If translation doesn't exist, fall back to original message
        if ($message === $translationKey) {
            $message = $this->warningDefinitions[$key]['setting-message'];
        }

        return $this->replaceParameters($message, $parameters);
    }

    /**
     * Replace parameters in a message string
     */
    private function replaceParameters(string $message, array $parameters): string
    {
        foreach ($parameters as $param => $value) {
            $message = str_replace(':' . $param, $value, $message);
        }
        return $message;
    }

    /**
     * Get available locales for warnings
     */
    public function getAvailableLocales(): array
    {
        $langPath = resource_path('lang');
        if (!is_dir($langPath)) {
            $langPath = base_path('lang');
        }

        $locales = [];
        if (is_dir($langPath)) {
            foreach (glob($langPath . '/*/warnings.php') as $file) {
                $locale = basename(dirname($file));
                $locales[] = $locale;
            }
        }

        return $locales;
    }

    /**
     * Check if a translation exists for a specific warning key
     */
    public function hasTranslation(string $key, string $type = 'warning', ?string $locale = null): bool
    {
        $locale = $locale ?? app()->getLocale();

        if ($type === 'setting') {
            $translationKey = "warnings.settings.{$key}";
        } else {
            $translationKey = "warnings.{$key}";
        }

        // Use Lang::has() to check if translation exists without fallback
        return Lang::has($translationKey, $locale, false);
    }
}
