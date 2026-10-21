<?php

/**
 * -------------------------------------------------------------------------
 * GLPI Service Desk Analytics
 * -------------------------------------------------------------------------
 *
 * Plugin de relatórios gerenciais para o GLPI 11.
 *
 * @author    Herbert
 * @copyright 2026 Herbert
 * @license   GPL-2.0-or-later
 */

define('PLUGIN_ANALYTICS_VERSION', '0.6.0');
define('PLUGIN_ANALYTICS_MIN_GLPI', '11.0.0');
define('PLUGIN_ANALYTICS_MAX_GLPI', '11.1.0'); // exclusivo

/**
 * Metadados do plugin (exibidos em Configurar > Plugins).
 */
function plugin_version_analytics(): array
{
    return [
        'name'         => 'Service Desk Analytics',
        'version'      => PLUGIN_ANALYTICS_VERSION,
        'author'       => 'Herbert',
        'license'      => 'GPLv2+',
        'homepage'     => '',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_ANALYTICS_MIN_GLPI,
                'max' => PLUGIN_ANALYTICS_MAX_GLPI,
            ],
            'php'  => [
                'min' => '8.2',
            ],
        ],
    ];
}

/**
 * Verificação de pré-requisitos antes da instalação/ativação.
 */
function plugin_analytics_check_prerequisites(): bool
{
    if (version_compare(PHP_VERSION, '8.2', '<')) {
        echo 'Service Desk Analytics requer PHP 8.2 ou superior.';
        return false;
    }

    if (
        version_compare(GLPI_VERSION, PLUGIN_ANALYTICS_MIN_GLPI, '<')
        || version_compare(GLPI_VERSION, PLUGIN_ANALYTICS_MAX_GLPI, '>=')
    ) {
        echo sprintf(
            'Service Desk Analytics requer GLPI >= %s e < %s.',
            PLUGIN_ANALYTICS_MIN_GLPI,
            PLUGIN_ANALYTICS_MAX_GLPI
        );
        return false;
    }

    return true;
}

/**
 * Verificação de configuração (o plugin não possui configuração própria).
 */
function plugin_analytics_check_config($verbose = false): bool
{
    return true;
}

/**
 * Inicialização: registra o relatório no menu Gerência.
 */
function plugin_init_analytics(): void
{
    global $PLUGIN_HOOKS;

    if (!Plugin::isPluginActive('analytics')) {
        return;
    }

    if (Session::getLoginUserID() && Session::haveRight('ticket', READ)) {
        $PLUGIN_HOOKS['menu_toadd']['analytics'] = [
            'management' => 'PluginAnalyticsMonthlyReport',
        ];
    }
}
