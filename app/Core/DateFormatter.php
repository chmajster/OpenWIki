<?php

declare(strict_types=1);

namespace OpenWiki\Core;

final class DateFormatter
{
    private const FORMATS = [
        'locale' => 'Use interface language',
        'd.m.Y' => 'DD.MM.YYYY',
        'Y-m-d' => 'YYYY-MM-DD',
        'm/d/Y' => 'MM/DD/YYYY',
        'd/m/Y' => 'DD/MM/YYYY',
    ];

    public static function choices(): array
    {
        return self::FORMATS;
    }

    public static function supports(string $format): bool
    {
        return array_key_exists($format, self::FORMATS);
    }

    public static function formatDateTime(?string $value, string $format, string $locale): string
    {
        if ($value === null || $value === '') return '';

        try {
            $date = new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
        } catch (\Throwable) {
            return $value;
        }

        $pattern = match (self::supports($format) ? $format : 'locale') {
            'd.m.Y' => 'd.m.Y',
            'Y-m-d' => 'Y-m-d',
            'm/d/Y' => 'm/d/Y',
            'd/m/Y' => 'd/m/Y',
            default => $locale === 'en' ? 'm/d/Y' : 'd.m.Y',
        };

        return $date->format($pattern . ' H:i');
    }

    public static function machineValue(?string $value): string
    {
        if ($value === null || $value === '') return '';

        try {
            return (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
        } catch (\Throwable) {
            return $value;
        }
    }
}
