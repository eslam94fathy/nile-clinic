<?php

namespace Tests\Feature\Localization;

use Illuminate\Support\Arr;
use Tests\TestCase;

/**
 * Every English translation file has an Arabic counterpart with the same keys (FR-018).
 */
class TranslationParityTest extends TestCase
{
    public function test_arabic_files_have_the_same_keys_as_english(): void
    {
        $englishFiles = glob(lang_path('en/*.php'));

        $this->assertNotEmpty($englishFiles, 'No English translation files in lang/en.');

        foreach ($englishFiles as $englishFile) {
            $name = basename($englishFile);
            $arabicFile = lang_path("ar/{$name}");

            $this->assertFileExists($arabicFile);
            $this->assertSame(
                $this->keys($englishFile),
                $this->keys($arabicFile),
                "lang/ar/{$name} keys differ from lang/en/{$name}.",
            );
        }
    }

    /**
     * @return list<string>
     */
    private function keys(string $file): array
    {
        $keys = array_keys(Arr::dot(require $file));
        sort($keys);

        return $keys;
    }
}
