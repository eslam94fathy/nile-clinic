<?php

namespace Tests\Unit\Support;

use App\Support\ArabicName;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Name normalization for Arabic-aware search (research R3, SC-003).
 */
class ArabicNameTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function normalizedNames(): array
    {
        return [
            'spaces and hamza on alef' => ['  أحمد   محمود ', 'احمد محمود'],
            'hamza below alef' => ['إيمان', 'ايمان'],
            'madda on alef' => ['آمال', 'امال'],
            'taa marbuta' => ['فاطمة', 'فاطمه'],
            'alef maqsura' => ['مصطفى', 'مصطفي'],
            'latin case and spaces' => ['Mostafa  ALI', 'mostafa ali'],
            'already normalized' => ['احمد', 'احمد'],
        ];
    }

    #[DataProvider('normalizedNames')]
    public function test_normalize(string $name, string $expected): void
    {
        $this->assertSame($expected, ArabicName::normalize($name));
    }

    public function test_clean_only_fixes_spaces(): void
    {
        $this->assertSame('أحمد محمود', ArabicName::clean('  أحمد   محمود '));
    }
}
