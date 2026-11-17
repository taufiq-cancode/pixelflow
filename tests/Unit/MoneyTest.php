<?php

namespace Tests\Unit;

use App\Support\Money;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    /**
     * @return array<string, array{string, int}>
     */
    public static function usdAmounts(): array
    {
        return [
            'docs usage example' => ['0.169080000', 169_080_000],
            'docs worked example' => ['0.373875', 373_875_000],
            'zero' => ['0.000000000', 0],
            'one nanodollar' => ['0.000000001', 1],
            'whole dollars' => ['25', 25_000_000_000],
            'trailing zeros beyond nine places' => ['1.0000000000', 1_000_000_000],
        ];
    }

    #[DataProvider('usdAmounts')]
    public function test_it_converts_usd_strings_to_nanodollars(string $usd, int $nanos)
    {
        $this->assertSame($nanos, Money::usdToNanos($usd));
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function rates(): array
    {
        return [
            'input text' => ['5.00', 5_000],
            'input image' => ['10.00', 10_000],
            'output' => ['40.00', 40_000],
            'reasoning' => ['12.50', 12_500],
            'single decimal' => ['12.5', 12_500],
            'finest representable rate' => ['0.001', 1],
            'trailing zeros' => ['12.5000', 12_500],
        ];
    }

    #[DataProvider('rates')]
    public function test_it_converts_rates_per_million_tokens_to_nanodollars_per_token(string $rate, int $nanosPerToken)
    {
        $this->assertSame($nanosPerToken, Money::ratePerMillionToNanosPerToken($rate));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidAmounts(): array
    {
        return [
            'empty' => [''],
            'negative' => ['-1.00'],
            'exponent' => ['1e3'],
            'comma' => ['1,00'],
            'padded' => [' 1.00'],
            'text' => ['abc'],
            'bare dot' => ['.5'],
        ];
    }

    #[DataProvider('invalidAmounts')]
    public function test_it_rejects_malformed_amounts(string $value)
    {
        $this->expectException(InvalidArgumentException::class);

        Money::usdToNanos($value);
    }

    public function test_it_refuses_to_round_a_rate_finer_than_one_nanodollar_per_token()
    {
        $this->expectException(InvalidArgumentException::class);

        Money::ratePerMillionToNanosPerToken('12.5005');
    }

    public function test_it_refuses_to_round_an_amount_finer_than_one_nanodollar()
    {
        $this->expectException(InvalidArgumentException::class);

        Money::usdToNanos('0.0000000001');
    }

    public function test_it_formats_nanodollars_as_exact_nine_decimal_strings()
    {
        $this->assertSame('0.169080000', Money::format(169_080_000));
        $this->assertSame('0.000000000', Money::format(0));
        $this->assertSame('0.000000001', Money::format(1));
        $this->assertSame('12345.000000000', Money::format(12_345_000_000_000));
        $this->assertSame('-0.000001250', Money::format(-1_250));
    }

    public function test_formatting_and_parsing_round_trip()
    {
        foreach ([0, 1, 999_999_999, 1_000_000_000, 467_343_750] as $nanos) {
            $this->assertSame($nanos, Money::usdToNanos(Money::format($nanos)));
        }
    }
}
