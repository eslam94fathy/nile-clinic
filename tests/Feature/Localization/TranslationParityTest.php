<?php

namespace Tests\Feature\Localization;

use Illuminate\Support\Arr;
use Tests\TestCase;

/**
 * Every English translation file has an Arabic counterpart with the same keys (FR-018),
 * both for framework messages in lang/ and for module messages in app/Modules/<Domain>/lang/.
 */
class TranslationParityTest extends TestCase
{
    public function test_arabic_files_have_the_same_keys_as_english(): void
    {
        $englishFiles = glob(lang_path('en/*.php'));

        $this->assertNotEmpty($englishFiles, 'No English translation files in lang/en.');

        $this->assertParity($englishFiles);
    }

    public function test_module_arabic_files_have_the_same_keys_as_english(): void
    {
        $englishFiles = glob(app_path('Modules/*/lang/en/*.php'));

        $this->assertNotEmpty($englishFiles, 'No English translation files in app/Modules/*/lang/en.');

        $this->assertParity($englishFiles);
    }

    /**
     * @param  list<string>  $englishFiles
     */
    private function assertParity(array $englishFiles): void
    {
        foreach ($englishFiles as $englishFile) {
            $arabicFile = dirname($englishFile, 2).'/ar/'.basename($englishFile);

            $this->assertFileExists($arabicFile);
            $this->assertSame(
                $this->keys($englishFile),
                $this->keys($arabicFile),
                "{$arabicFile} keys differ from {$englishFile}.",
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
