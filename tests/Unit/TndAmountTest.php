<?php

namespace Tests\Unit;

use App\Support\TndAmount;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TndAmountTest extends TestCase
{
    public static function validAmounts(): array
    {
        return [
            ['12', 12000], ['12,5', 12500], ['12.5', 12500], ['12,05', 12050],
            ['0.001', 1], ['0,01', 10], ['000012.500', 12500], ['999999999,999', 999999999999],
        ];
    }

    #[DataProvider('validAmounts')]
    public function test_decimal_strings_are_parsed_exactly(string $input, int $expected): void
    {
        $this->assertSame($expected, TndAmount::parse($input));
        $this->assertSame($expected, TndAmount::parse(TndAmount::decimal($expected)));
    }

    public static function invalidAmounts(): array
    {
        return array_map(fn ($value) => [$value], [
            '', '0', '0.000', '-1', '+1', '1.0000', '1,2.3', '1,,2', '1e3', 'NaN', 'INF',
            ' 12', '12 ', '1 234', '1_234', '.5', '1.', '١٢', '1000000000', str_repeat('9', 100),
        ]);
    }

    #[DataProvider('invalidAmounts')]
    public function test_invalid_or_out_of_bounds_inputs_are_rejected(string $input): void
    {
        $this->expectException(InvalidArgumentException::class);
        TndAmount::parse($input);
    }

    public function test_formatting_never_converts_large_integers_to_floats(): void
    {
        $this->assertSame('0,000 TND', TndAmount::format(0));
        $this->assertSame('0,001 TND', TndAmount::format(1));
        $this->assertSame("999\u{202F}999\u{202F}999,999 TND", TndAmount::format(999999999999));
        $this->assertSame("9\u{202F}223\u{202F}372\u{202F}036\u{202F}854\u{202F}775,807 TND", TndAmount::format(PHP_INT_MAX));
    }
}
