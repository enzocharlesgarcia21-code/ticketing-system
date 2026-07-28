<?php

/**
 * Ticket SLA calendar.
 *
 * SLA time runs from 08:00 through 17:00, Monday to Friday, in Asia/Manila.
 * The dated entries below are nationwide regular and special non-working days.
 * Local holidays are intentionally not included because ticket routing is shared
 * across several Philippine locations.
 *
 * Annual, Eid, and other proclamation-based holidays must be added here when a
 * new nationwide proclamation is published.
 */

function ticket_sla_timezone(): DateTimeZone
{
    static $timezone = null;
    if (!$timezone instanceof DateTimeZone) {
        $timezone = new DateTimeZone('Asia/Manila');
    }
    return $timezone;
}

function ticket_sla_workday_seconds(): int
{
    return 9 * 60 * 60;
}

function ticket_sla_holidays_by_year(): array
{
    return [
        // Proclamation No. 368, s. 2023, plus the nationwide Eid proclamations.
        2024 => [
            '2024-01-01', '2024-02-09', '2024-03-28', '2024-03-29',
            '2024-04-09', '2024-04-10', '2024-05-01', '2024-06-12',
            '2024-06-17', '2024-08-21', '2024-08-26', '2024-11-01',
            '2024-11-30', '2024-12-08', '2024-12-24', '2024-12-25',
            '2024-12-30', '2024-12-31',
        ],
        // Proclamation No. 727, s. 2024; Proclamations Nos. 839 and 911, s. 2025.
        2025 => [
            '2025-01-01', '2025-01-29', '2025-04-01', '2025-04-09',
            '2025-04-17', '2025-04-18', '2025-04-19', '2025-05-01',
            '2025-06-06', '2025-06-12', '2025-08-21', '2025-08-25',
            '2025-10-31', '2025-11-01', '2025-11-30', '2025-12-08',
            '2025-12-24', '2025-12-25', '2025-12-30', '2025-12-31',
        ],
        // Proclamation No. 1006, s. 2025; Proclamations Nos. 1189 and 1264, s. 2026.
        2026 => [
            '2026-01-01', '2026-02-17', '2026-03-20', '2026-04-02',
            '2026-04-03', '2026-04-04', '2026-04-09', '2026-05-01',
            '2026-05-27', '2026-06-12', '2026-08-21', '2026-08-31',
            '2026-11-01', '2026-11-02', '2026-11-30', '2026-12-08',
            '2026-12-24', '2026-12-25', '2026-12-30', '2026-12-31',
        ],
    ];
}

function ticket_sla_fallback_holidays_for_year(int $year): array
{
    if ($year < 1970 || $year > 9999) return [];

    $dates = [
        sprintf('%04d-01-01', $year),
        sprintf('%04d-04-09', $year),
        sprintf('%04d-05-01', $year),
        sprintf('%04d-06-12', $year),
        sprintf('%04d-08-21', $year),
        sprintf('%04d-11-01', $year),
        sprintf('%04d-11-30', $year),
        sprintf('%04d-12-08', $year),
        sprintf('%04d-12-25', $year),
        sprintf('%04d-12-30', $year),
        sprintf('%04d-12-31', $year),
    ];

    $timezone = ticket_sla_timezone();
    $augustLastDay = new DateTimeImmutable(sprintf('%04d-08-31', $year), $timezone);
    $dates[] = $augustLastDay->modify('monday this week')->format('Y-m-d');

    // These nationwide Holy Week dates are derived from Easter. Black Saturday
    // is included for completeness even though it already falls on a weekend.
    $easter = (new DateTimeImmutable('@' . easter_date($year)))->setTimezone($timezone)->setTime(0, 0);
    $dates[] = $easter->modify('-3 days')->format('Y-m-d');
    $dates[] = $easter->modify('-2 days')->format('Y-m-d');
    $dates[] = $easter->modify('-1 day')->format('Y-m-d');

    return array_values(array_unique($dates));
}

function ticket_sla_holiday_dates_for_year(int $year): array
{
    $configured = ticket_sla_holidays_by_year();
    $dates = $configured[$year] ?? ticket_sla_fallback_holidays_for_year($year);
    sort($dates);
    return array_values(array_unique($dates));
}

function ticket_sla_holiday_lookup_for_year(int $year): array
{
    static $cache = [];
    if (!isset($cache[$year])) {
        $cache[$year] = array_fill_keys(ticket_sla_holiday_dates_for_year($year), true);
    }
    return $cache[$year];
}

function ticket_sla_is_business_date(DateTimeInterface $date): bool
{
    $weekday = (int) $date->format('N');
    if ($weekday > 5) return false;
    $holidays = ticket_sla_holiday_lookup_for_year((int) $date->format('Y'));
    return !isset($holidays[$date->format('Y-m-d')]);
}

function ticket_sla_datetime($value): ?DateTimeImmutable
{
    if ($value instanceof DateTimeInterface) {
        return (new DateTimeImmutable($value->format('Y-m-d H:i:s.u'), $value->getTimezone()))
            ->setTimezone(ticket_sla_timezone());
    }
    $value = trim((string) $value);
    if ($value === '') return null;
    try {
        return (new DateTimeImmutable($value, ticket_sla_timezone()))->setTimezone(ticket_sla_timezone());
    } catch (Throwable $e) {
        return null;
    }
}

function ticket_sla_business_seconds_between($startValue, $endValue = null): int
{
    $start = ticket_sla_datetime($startValue);
    $end = $endValue === null
        ? new DateTimeImmutable('now', ticket_sla_timezone())
        : ticket_sla_datetime($endValue);
    if (!$start || !$end || $end <= $start) return 0;

    $seconds = 0;
    $day = $start->setTime(0, 0, 0);
    $lastDay = $end->setTime(0, 0, 0);
    while ($day <= $lastDay) {
        if (ticket_sla_is_business_date($day)) {
            $windowStart = $day->setTime(8, 0, 0);
            $windowEnd = $day->setTime(17, 0, 0);
            $overlapStart = $start > $windowStart ? $start : $windowStart;
            $overlapEnd = $end < $windowEnd ? $end : $windowEnd;
            if ($overlapEnd > $overlapStart) {
                $seconds += $overlapEnd->getTimestamp() - $overlapStart->getTimestamp();
            }
        }
        $day = $day->modify('+1 day');
    }
    return max(0, $seconds);
}

function ticket_business_seconds_between($startValue, $endValue = null): int
{
    return ticket_sla_business_seconds_between($startValue, $endValue);
}

function ticket_format_business_duration(int $totalSeconds): string
{
    $seconds = max(0, $totalSeconds);
    $hours = intdiv($seconds, 3600);
    $minutes = intdiv($seconds % 3600, 60);
    $remainingSeconds = $seconds % 60;
    $parts = [];
    if ($hours > 0) $parts[] = $hours . ($hours === 1 ? ' hr' : ' hrs');
    if ($minutes > 0 || $hours > 0) $parts[] = $minutes . ($minutes === 1 ? ' min' : ' mins');
    $parts[] = $remainingSeconds . ($remainingSeconds === 1 ? ' sec' : ' secs');
    return implode(' ', $parts);
}

function ticket_format_business_duration_clock(int $totalSeconds): string
{
    $seconds = max(0, $totalSeconds);
    $hours = intdiv($seconds, 3600);
    $minutes = intdiv($seconds % 3600, 60);
    $remainingSeconds = $seconds % 60;
    return sprintf('%02d:%02d:%02d', $hours, $minutes, $remainingSeconds);
}

function ticket_sla_add_business_seconds($startValue, int $seconds): ?DateTimeImmutable
{
    $cursor = ticket_sla_datetime($startValue);
    if (!$cursor) return null;
    $remaining = max(0, $seconds);
    if ($remaining === 0) return $cursor;

    while (true) {
        $day = $cursor->setTime(0, 0, 0);
        if (ticket_sla_is_business_date($day)) {
            $windowStart = $day->setTime(8, 0, 0);
            $windowEnd = $day->setTime(17, 0, 0);
            if ($cursor < $windowStart) $cursor = $windowStart;
            if ($cursor < $windowEnd) {
                $available = $windowEnd->getTimestamp() - $cursor->getTimestamp();
                if ($remaining <= $available) {
                    return $cursor->modify('+' . $remaining . ' seconds');
                }
                $remaining -= $available;
            }
        }
        $cursor = $day->modify('+1 day')->setTime(8, 0, 0);
    }
}

function ticket_sla_sql_holiday_dates(): array
{
    $currentYear = (int) (new DateTimeImmutable('now', ticket_sla_timezone()))->format('Y');
    $dates = [];
    for ($year = 2020; $year <= $currentYear + 2; $year++) {
        foreach (ticket_sla_holiday_dates_for_year($year) as $date) {
            $weekday = (int) (new DateTimeImmutable($date, ticket_sla_timezone()))->format('N');
            if ($weekday <= 5) $dates[] = $date;
        }
    }
    sort($dates);
    return array_values(array_unique($dates));
}

function ticket_sla_business_seconds_sql(string $startExpression, string $endExpression = 'NOW()'): string
{
    $start = '(' . $startExpression . ')';
    $end = '(' . $endExpression . ')';
    $spanDays = "GREATEST(DATEDIFF(DATE($end), DATE($start)) + 1, 0)";
    $remainder = "MOD($spanDays, 7)";
    $weekdays = "(FLOOR($spanDays / 7) * 5"
        . " + LEAST($remainder, GREATEST(0, 5 - WEEKDAY(DATE($start))))"
        . " + GREATEST(0, $remainder - (7 - WEEKDAY(DATE($start)))))";

    $holidayDates = ticket_sla_sql_holiday_dates();
    $holidayCountParts = [];
    foreach ($holidayDates as $date) {
        $holidayCountParts[] = "CASE WHEN DATE($start) <= '$date' AND DATE($end) >= '$date' THEN 1 ELSE 0 END";
    }
    $holidayCount = count($holidayCountParts) > 0 ? '(' . implode(' + ', $holidayCountParts) . ')' : '0';
    $holidayList = count($holidayDates) > 0
        ? "'" . implode("','", $holidayDates) . "'"
        : "'1900-01-01'";

    $startWorkday = "(WEEKDAY(DATE($start)) < 5 AND DATE($start) NOT IN ($holidayList))";
    $endWorkday = "(WEEKDAY(DATE($end)) < 5 AND DATE($end) NOT IN ($holidayList))";
    $startWindow = "TIMESTAMP(DATE($start), '08:00:00')";
    $startWindowEnd = "TIMESTAMP(DATE($start), '17:00:00')";
    $endWindow = "TIMESTAMP(DATE($end), '08:00:00')";
    $endWindowEnd = "TIMESTAMP(DATE($end), '17:00:00')";
    $startTrim = "CASE WHEN $startWorkday THEN TIMESTAMPDIFF(SECOND, $startWindow, LEAST(GREATEST($start, $startWindow), $startWindowEnd)) ELSE 0 END";
    $endTrim = "CASE WHEN $endWorkday THEN TIMESTAMPDIFF(SECOND, LEAST(GREATEST($end, $endWindow), $endWindowEnd), $endWindowEnd) ELSE 0 END";

    return "GREATEST(0, (GREATEST($weekdays - $holidayCount, 0) * " . ticket_sla_workday_seconds() . ") - ($startTrim) - ($endTrim))";
}

function ticket_business_seconds_sql(string $startExpression, string $endExpression = 'NOW()'): string
{
    return ticket_sla_business_seconds_sql($startExpression, $endExpression);
}
