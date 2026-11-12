<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

final class TndAmount
{
    public const MAX_MILLIMES = 999999999999;

    public static function parse(string $amount, int $maximumMillimes = self::MAX_MILLIMES): int
    {
        if ($maximumMillimes < 1 || $maximumMillimes > self::MAX_MILLIMES) {
            throw new InvalidArgumentException('La limite de montant est invalide.');
        }
        if (! preg_match('/\A([0-9]+)(?:[,.]([0-9]{1,3}))?\z/', $amount, $parts)) {
            throw new InvalidArgumentException('Saisissez un montant positif avec au plus trois décimales, séparées par une virgule ou un point.');
        }

        $digits = ltrim($parts[1].str_pad($parts[2] ?? '', 3, '0'), '0');
        $maximum = (string) $maximumMillimes;
        if ($digits === '' || strlen($digits) > strlen($maximum) || (strlen($digits) === strlen($maximum) && strcmp($digits, $maximum) > 0)) {
            throw new InvalidArgumentException('Le montant doit être compris entre 0,001 et '.self::format($maximumMillimes).'.');
        }

        return (int) $digits;
    }

    public static function decimal(int $millimes): string
    {
        if ($millimes < 0) {
            throw new InvalidArgumentException('Le montant ne peut pas être négatif.');
        }

        return intdiv($millimes, 1000).','.str_pad((string) ($millimes % 1000), 3, '0', STR_PAD_LEFT);
    }

    public static function format(int $millimes): string
    {
        [$whole, $fraction] = explode(',', self::decimal($millimes));

        return preg_replace('/\B(?=(\d{3})+(?!\d))/', "\u{202F}", $whole).','.$fraction.' TND';
    }
}
