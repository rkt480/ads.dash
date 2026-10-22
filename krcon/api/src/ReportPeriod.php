<?php
declare(strict_types=1);

final class ReportPeriod
{
    public static function range(string $period, ?array $customRange = null): array
    {
        $timezoneName = api_env('DASHBOARD_TIMEZONE') ?? 'America/Sao_Paulo';
        try {
            $timezone = new DateTimeZone($timezoneName);
        } catch (Throwable $error) {
            $timezone = new DateTimeZone('America/Sao_Paulo');
        }

        if ($period === 'custom') {
            $since = (string) ($customRange['since'] ?? '');
            $until = (string) ($customRange['until'] ?? '');
            if (!self::isValidDateRange($since, $until)) {
                throw new InvalidArgumentException('invalid_report_range');
            }
            return ['since' => $since, 'until' => $until];
        }

        $today = new DateTimeImmutable('today', $timezone);
        if ($period === '7d') {
            $end = $today->modify('-1 day');
            $start = $end->modify('-6 days');
        } elseif ($period === '90d') {
            $start = $today->modify('-89 days');
            $end = $today;
        } elseif ($period === 'this_month') {
            $start = $today->modify('first day of this month');
            $end = $today;
        } elseif ($period === 'last_month') {
            $start = $today->modify('first day of last month');
            $end = $today->modify('last day of last month');
        } else {
            $start = $today->modify('-29 days');
            $end = $today;
        }

        return ['since' => $start->format('Y-m-d'), 'until' => $end->format('Y-m-d')];
    }

    public static function isValidDateRange(string $since, string $until): bool
    {
        if (!preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D', $since, $startParts)
            || !preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D', $until, $endParts)) {
            return false;
        }

        $validStart = checkdate((int) $startParts[2], (int) $startParts[3], (int) $startParts[1]);
        $validEnd = checkdate((int) $endParts[2], (int) $endParts[3], (int) $endParts[1]);
        return $validStart && $validEnd && $since <= $until;
    }
}
