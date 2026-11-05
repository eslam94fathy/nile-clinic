<?php

namespace Tests\Feature\Encounters;

use RuntimeException;
use Tests\TestCase;

/**
 * Clinical content never reaches logs or error responses (FR-021, SC-002, research R6).
 */
class EncounterPrivacyTest extends TestCase
{
    private const PLANTED = 'SYNTHETIC-PLANTED-TEXT';

    /**
     * With arguments in traces, a failing call prints the first 15 characters of a string argument.
     */
    public function test_stack_traces_carry_no_arguments(): void
    {
        $this->assertSame('1', ini_get('zend.exception_ignore_args'));

        $fail = function (string $text): void {
            throw new RuntimeException('failed');
        };

        try {
            $fail(self::PLANTED);
            $this->fail('The closure should have thrown.');
        } catch (RuntimeException $e) {
            $this->assertStringNotContainsString('SYNTHETIC', $e->getTraceAsString());
        }
    }
}
