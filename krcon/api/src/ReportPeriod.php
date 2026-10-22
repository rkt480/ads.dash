<?php
declare(strict_types=1);

final class ReportPeriod
{
    public static function range(string $period): array
    {
        $timezoneName = api_env('DASHBOARD_TIMEZONE') ?? 'America/Sao_Paulo';
        try {
            $timezone = new DateTimeZone($timezoneName);
        } catch (Throwable $error) {
            $timezone = new DateTimeZone('America/Sao_Paulo');
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
}
