<?php

use App\Services\RecurringIncomeService;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

uses(TestCase::class);

function recurrenceService(): RecurringIncomeService
{
    return app(RecurringIncomeService::class);
}

test('weekly recurrence includes start and end dates exactly seven days apart', function () {
    $dates = recurrenceService()->occurrenceDates('weekly', '2026-11-01', '2026-11-15');

    expect($dates)->toBe(['2026-11-01', '2026-11-08', '2026-11-15']);
});

test('a one-day range produces one occurrence', function () {
    expect(recurrenceService()->occurrenceDates('weekly', '2026-11-01', '2026-11-01'))
        ->toBe(['2026-11-01']);
});

test('an end date before the start date is rejected', function () {
    recurrenceService()->occurrenceDates('weekly', '2026-11-02', '2026-11-01');
})->throws(ValidationException::class);

test('weekly recurrence crosses month and year boundaries', function () {
    expect(recurrenceService()->occurrenceDates('weekly', '2026-12-29', '2027-01-12'))
        ->toBe(['2026-12-29', '2027-01-05', '2027-01-12']);
});

test('daily recurrence includes leap day', function () {
    expect(recurrenceService()->occurrenceDates('daily', '2028-02-28', '2028-03-01'))
        ->toBe(['2028-02-28', '2028-02-29', '2028-03-01']);
});

test('monthly recurrence stays anchored to the original day of month', function () {
    expect(recurrenceService()->occurrenceDates('monthly', '2028-01-31', '2028-04-30'))
        ->toBe(['2028-01-31', '2028-02-29', '2028-03-31', '2028-04-30']);
});

test('quarterly, half-yearly and yearly recurrences use calendar months', function () {
    expect(recurrenceService()->occurrenceDates('quarterly', '2026-01-15', '2026-12-31'))
        ->toBe(['2026-01-15', '2026-04-15', '2026-07-15', '2026-10-15']);

    expect(recurrenceService()->occurrenceDates('half-yearly', '2026-01-15', '2027-01-15'))
        ->toBe(['2026-01-15', '2026-07-15', '2027-01-15']);

    expect(recurrenceService()->occurrenceDates('yearly', '2026-02-28', '2028-02-29'))
        ->toBe(['2026-02-28', '2027-02-28', '2028-02-28']);
});

test('a range above the occurrence limit is rejected instead of truncated', function () {
    recurrenceService()->occurrenceDates('daily', '2026-01-01', '2027-01-02');
})->throws(ValidationException::class);

test('exactly the occurrence limit is accepted', function () {
    // 2026 is not a leap year, so this is 366 inclusive calendar days.
    $dates = recurrenceService()->occurrenceDates('daily', '2026-01-01', '2027-01-01');

    expect($dates)->toHaveCount(RecurringIncomeService::MAX_OCCURRENCES);
});

test('repeated calculation is deterministic', function () {
    $service = recurrenceService();
    $first = $service->occurrenceDates('biweekly', '2026-11-01', '2027-02-01');
    $second = $service->occurrenceDates('biweekly', '2026-11-01', '2027-02-01');

    expect($second)->toBe($first);
});

test('invalid frequencies are rejected', function () {
    recurrenceService()->occurrenceDates('fortnightly', '2026-11-01', '2026-11-30');
})->throws(ValidationException::class);

test('preview totals use decimal cents rather than floating point', function () {
    $preview = recurrenceService()->preview('weekly', '500.00', '2026-11-01', '2026-11-15');

    expect($preview['count'])->toBe(3)
        ->and($preview['total_expected'])->toBe('1500.00')
        ->and($preview['dates'])->toBe(['2026-11-01', '2026-11-08', '2026-11-15']);
});

test('monthly preview handles a leap-year February', function () {
    $preview = recurrenceService()->preview('monthly', '99.99', '2028-01-31', '2028-02-29');

    expect($preview['dates'])->toBe(['2028-01-31', '2028-02-29'])
        ->and($preview['total_expected'])->toBe('199.98');
});

test('date calculations are independent of the server timezone', function () {
    $original = date_default_timezone_get();

    try {
        date_default_timezone_set('Pacific/Kiritimati');
        $dates = recurrenceService()->occurrenceDates('weekly', '2026-03-07', '2026-03-21');
        expect($dates)->toBe(['2026-03-07', '2026-03-14', '2026-03-21']);

        date_default_timezone_set('America/Los_Angeles');
        $dates = recurrenceService()->occurrenceDates('weekly', '2026-03-07', '2026-03-21');
        expect($dates)->toBe(['2026-03-07', '2026-03-14', '2026-03-21']);
    } finally {
        date_default_timezone_set($original);
        CarbonImmutable::setTestNow();
    }
});
