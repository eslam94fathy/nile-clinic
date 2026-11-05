<?php

namespace Tests\Unit\Payments;

use App\Modules\Payments\Support\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Money is calculated in whole piastres, so floats can never change an amount (SC-004, research R2).
 */
class MoneyTest extends TestCase
{
    /**
     * @return array<string, array{string|int|float, int}>
     */
    public static function amounts(): array
    {
        return [
            'two decimals' => ['150.00', 15000],
            'one decimal' => ['150.5', 15050],
            'no decimals' => ['150', 15000],
            'an integer' => [150, 15000],
            'a float' => [150.25, 15025],
            'piastres only' => ['0.07', 7],
            'no whole part' => ['.5', 50],
            'a trailing point' => ['5.', 500],
            'the limit' => ['100000', 10000000],
            'a float that drifts when multiplied' => [19.99, 1999],
            'another one' => [1.15, 115],
        ];
    }

    #[DataProvider('amounts')]
    public function test_to_piastres(string|int|float $amount, int $piastres): void
    {
        $this->assertSame($piastres, Money::toPiastres($amount));
    }

    public function test_from_piastres(): void
    {
        $this->assertSame('150.00', Money::fromPiastres(15000));
        $this->assertSame('0.07', Money::fromPiastres(7));
        $this->assertSame('0.00', Money::fromPiastres(0));
        $this->assertSame('0.05', Money::fromPiastres(5));
        $this->assertSame('100000.00', Money::fromPiastres(10000000));
    }

    public function test_from_piastres_keeps_the_sign(): void
    {
        $this->assertSame('-1.50', Money::fromPiastres(-150));
        $this->assertSame('-0.50', Money::fromPiastres(-50));
        $this->assertSame('-0.05', Money::fromPiastres(-5));
        $this->assertSame('-150.00', Money::fromPiastres(-15000));
    }

    public function test_due_and_balance(): void
    {
        $this->assertSame('250.00', Money::due('400.00', '150.00'));
        $this->assertSame('149.90', Money::balance('400.00', '150.00', '100.10'));
        $this->assertSame('0.00', Money::balance('0.30', '0.00', '0.30'));
    }

    public function test_piastres_do_not_drift_where_floats_do(): void
    {
        $this->assertNotSame(0.3, 0.1 + 0.2);
        $this->assertSame('0.30', Money::fromPiastres(Money::toPiastres('0.10') + Money::toPiastres('0.20')));
    }
}
