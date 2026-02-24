<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests for worstStatus() in includes/functions.php
 *
 * Run with: vendor/bin/phpunit tests/WorstStatusTest.php
 */
class WorstStatusTest extends TestCase
{
    public function testSingleDangerReturnsDanger(): void
    {
        $this->assertSame('danger', worstStatus(['danger']));
    }

    public function testSingleWarningReturnsWarning(): void
    {
        $this->assertSame('warning', worstStatus(['warning']));
    }

    public function testSingleSuccessReturnsSuccess(): void
    {
        $this->assertSame('success', worstStatus(['success']));
    }

    public function testSingleNoneReturnsNone(): void
    {
        $this->assertSame('none', worstStatus(['none']));
    }

    public function testDangerDominatesWarningAndSuccess(): void
    {
        $this->assertSame('danger', worstStatus(['success', 'warning', 'danger']));
    }

    public function testWarningDominatesSuccess(): void
    {
        $this->assertSame('warning', worstStatus(['success', 'warning']));
    }

    public function testAllSuccessReturnsSuccess(): void
    {
        $this->assertSame('success', worstStatus(['success', 'success', 'success']));
    }

    public function testEmptyArrayReturnsNone(): void
    {
        $this->assertSame('none', worstStatus([]));
    }

    public function testDangerDominatesAloneInMix(): void
    {
        $this->assertSame('danger', worstStatus(['none', 'success', 'danger', 'warning']));
    }
}
