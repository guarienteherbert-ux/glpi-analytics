<?php

/**
 * -------------------------------------------------------------------------
 * GLPI Service Desk Analytics - Relatório Mensal (menu)
 * -------------------------------------------------------------------------
 *
 * @author    Herbert
 * @copyright 2026 Herbert
 * @license   GPL-2.0-or-later
 */

class PluginAnalyticsMonthlyReport extends CommonGLPI
{
    public static $rightname = 'ticket';

    public static function getTypeName($nb = 0): string
    {
        return 'Relatório Mensal de Service Desk';
    }

    public static function getMenuName(): string
    {
        return 'Relatório Mensal';
    }

    public static function getIcon(): string
    {
        return 'ti ti-report-analytics';
    }

    public static function canView(): bool
    {
        return Session::haveRight(self::$rightname, READ);
    }

    public static function getMenuContent(): array
    {
        return [
            'title' => self::getMenuName(),
            'page'  => Plugin::getWebDir('analytics', false) . '/front/monthly_report.php',
            'icon'  => self::getIcon(),
        ];
    }
}
