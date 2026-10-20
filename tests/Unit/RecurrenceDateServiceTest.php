<?php

use App\Services\RecurrenceDateService;
use Carbon\CarbonImmutable;
use Tests\TestCase;

uses(TestCase::class);

test('open-ended generation starts at the first future occurrence and keeps the anchor', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-20 09:00:00'));

    $service = app(RecurrenceDateService::class);

    expect($service->generationStartDate('monthly', '2026-09-30', null))->toBe('2026-10-30');
    expect($service->generationStartDate('monthly', '2026-01-31', null))->toBe('2026-10-31');
    expect($service->generationStartDate('weekly', '2026-10-01', null))->toBe('2026-10-22');
    expect($service->generationStartDate('monthly', '2026-10-15', null))->toBe('2026-11-15');
});

test('a dated schedule keeps its start date and a future open-ended schedule is unchanged', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-20 09:00:00'));

    $service = app(RecurrenceDateService::class);

    expect($service->generationStartDate('monthly', '2026-09-30', '2026-12-31'))->toBe('2026-09-30');
    expect($service->generationStartDate('monthly', '2026-11-01', null))->toBe('2026-11-01');
});

test('a leap-day yearly schedule returns to February 29 in the next leap year', function () {
    $service = app(RecurrenceDateService::class);

    $start = CarbonImmutable::createFromFormat('!Y-m-d', '2028-02-29', 'UTC');
    $target = CarbonImmutable::createFromFormat('!Y-m-d', '2031-03-01', 'UTC');

    expect($service->firstOnOrAfter('yearly', $start, $target)->toDateString())->toBe('2032-02-29');
});

test('the rolling horizon is measured from the first future occurrence', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-20 09:00:00'));

    $service = app(RecurrenceDateService::class);

    expect($service->generationEndDate('monthly', '2026-09-30', null))->toBe('2027-10-30');
    expect($service->generationEndDate('weekly', '2026-09-01', null))->toBe('2027-10-20');
});
