<?php

/**
 * -------------------------------------------------------------------------
 * GLPI Service Desk Analytics - Instalação / Desinstalação
 * -------------------------------------------------------------------------
 *
 * @author    Herbert
 * @copyright 2026 Herbert
 * @license   GPL-2.0-or-later
 */

/**
 * O plugin apenas lê dados do GLPI: não cria tabelas nem direitos próprios.
 * O acesso é controlado pelo direito nativo "ticket" (leitura).
 */
function plugin_analytics_install(): bool
{
    return true;
}

function plugin_analytics_uninstall(): bool
{
    return true;
}
