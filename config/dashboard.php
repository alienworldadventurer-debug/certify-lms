<?php

declare(strict_types=1);

/**
 * 管理者ダッシュボード集計(全体 KPI / 資格別修了率)のキャッシュ設定。
 * キャッシュは EnrollmentStatsService が保持し、受講状態の遷移(EnrollmentStatusChangeService)で即時無効化する。
 * 受講状態の遷移を伴わない変化は ttl の失効で反映される。
 */
return [
    'admin_cache_ttl_seconds' => (int) env('DASHBOARD_ADMIN_CACHE_TTL', 300),
    'admin_kpi_cache_key' => 'dashboard.admin.kpi',
    'admin_completion_rate_cache_key' => 'dashboard.admin.completion_rate',
];
