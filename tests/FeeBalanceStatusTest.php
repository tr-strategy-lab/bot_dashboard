<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests for getFeeBalanceStatus() in includes/functions.php
 *
 * Run with: vendor/bin/phpunit tests/FeeBalanceStatusTest.php
 */
class FeeBalanceStatusTest extends TestCase
{
    private array $thresholds = [
        'visible' => 100,
        'warning' => 50,
        'danger'  => 30,
    ];

    public function testAboveVisibleThresholdShowsNoIndicator(): void
    {
        $result = getFeeBalanceStatus(100.0, $this->thresholds);
        $this->assertSame('none', $result['status']);
        $this->assertSame('', $result['indicator']);
    }

    public function testWellAboveThresholdShowsNoIndicator(): void
    {
        $result = getFeeBalanceStatus(500.0, $this->thresholds);
        $this->assertSame('none', $result['status']);
    }

    public function testBelowVisibleButAboveWarningShowsGreen(): void
    {
        $result = getFeeBalanceStatus(75.0, $this->thresholds);
        $this->assertSame('success', $result['status']);
        $this->assertSame('🟢', $result['indicator']);
    }

    public function testExactlyAtVisibleThresholdShowsNoIndicator(): void
    {
        $result = getFeeBalanceStatus(100.0, $this->thresholds);
        $this->assertSame('none', $result['status']);
    }

    public function testBelowWarningThresholdShowsYellow(): void
    {
        $result = getFeeBalanceStatus(49.0, $this->thresholds);
        $this->assertSame('warning', $result['status']);
        $this->assertSame('🟡', $result['indicator']);
    }

    public function testExactlyAtWarningThresholdShowsGreen(): void
    {
        $result = getFeeBalanceStatus(50.0, $this->thresholds);
        $this->assertSame('success', $result['status']);
    }

    public function testBelowDangerThresholdShowsRed(): void
    {
        $result = getFeeBalanceStatus(29.0, $this->thresholds);
        $this->assertSame('danger', $result['status']);
        $this->assertSame('🔴', $result['indicator']);
    }

    public function testExactlyAtDangerThresholdShowsYellow(): void
    {
        $result = getFeeBalanceStatus(30.0, $this->thresholds);
        $this->assertSame('warning', $result['status']);
    }

    public function testZeroShowsRed(): void
    {
        $result = getFeeBalanceStatus(0.0, $this->thresholds);
        $this->assertSame('danger', $result['status']);
    }
}
