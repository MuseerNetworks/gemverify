<?php

namespace Helpers;

class Sanitizer
{
    public static function string(mixed $value): string
    {
        if (is_null($value)) return '';
        return trim(strip_tags((string)$value));
    }

    public static function email(mixed $value): string
    {
        if (is_null($value)) return '';
        return filter_var(trim((string)$value), FILTER_SANITIZE_EMAIL);
    }

    public static function int(mixed $value): int
    {
        return (int)$value;
    }

    public static function float(mixed $value): float
    {
        return (float)$value;
    }

    public static function array(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    public static function filename(string $value): string
    {
        $value = preg_replace('/[^a-zA-Z0-9_.-]/', '', $value);
        return $value;
    }

    public static function cleanFormData(array $data): array
    {
        $cleaned = [];
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $cleaned[$key] = self::cleanFormData($value);
            } elseif (is_string($value)) {
                $cleaned[$key] = self::string($value);
            } else {
                $cleaned[$key] = $value;
            }
        }
        return $cleaned;
    }
}


