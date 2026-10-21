<?php

/**
 * -------------------------------------------------------------------------
 * GLPI Service Desk Analytics - Logos do relatório
 * -------------------------------------------------------------------------
 *
 * Dois tipos de logo, guardados em files/_plugins/analytics/ (fora da área
 * pública do GLPI):
 *
 *   - COMPANY: logo da empresa prestadora. Única, vale para todos os
 *     relatórios.
 *   - CLIENT:  logo do cliente. Uma por entidade. Se a entidade selecionada
 *     não tiver logo própria, usa a da entidade-pai mais próxima (a entidade
 *     raiz não é herdada, pois representa a própria empresa).
 *
 * Segurança: aceita só PNG/JPEG, valida com getimagesizefromstring(),
 * decodifica e REGRAVA a imagem com GD (descarta metadados e qualquer
 * conteúdo embutido) e limita as dimensões. Não aceita SVG.
 *
 * @author    Herbert
 * @copyright 2026 Herbert
 * @license   GPL-2.0-or-later
 */

class PluginAnalyticsLogo
{
    public const COMPANY = 'company';
    public const CLIENT  = 'client';

    public const MAX_BYTES  = 1048576;  // 1 MB de upload
    public const MAX_PIXELS = 8000000;  // evita estourar memória na decodificação
    public const MAX_W      = 600;
    public const MAX_H      = 240;

    private static function dir(): string
    {
        $base = defined('GLPI_PLUGIN_DOC_DIR')
            ? GLPI_PLUGIN_DOC_DIR
            : (defined('GLPI_VAR_DIR') ? GLPI_VAR_DIR . '/_plugins' : sys_get_temp_dir());

        return rtrim($base, '/\\') . '/analytics';
    }

    public static function isValidKind(string $kind): bool
    {
        return $kind === self::COMPANY || $kind === self::CLIENT;
    }

    private static function path(string $kind, int $entityId): string
    {
        return $kind === self::COMPANY
            ? self::dir() . '/logo-company.png'
            : self::dir() . '/logo-entity-' . $entityId . '.png';
    }

    /**
     * Entidade selecionada e suas entidades-pai (da mais próxima para a mais
     * distante), sem incluir a raiz, exceto se ela mesma for a selecionada.
     *
     * @return list<int>
     */
    private static function chain(int $entityId): array
    {
        $ids = [$entityId];
        $id  = $entityId;

        for ($i = 0; $i < 10 && $id > 0 && class_exists('Entity'); $i++) {
            $entity = new Entity();
            if (!$entity->getFromDB($id)) {
                break;
            }
            $id = (int) $entity->fields['entities_id'];
            if ($id <= 0) {
                break;
            }
            $ids[] = $id;
        }

        return $ids;
    }

    /** Existe logo própria deste tipo (para a entidade, no caso do cliente)? */
    public static function hasOwn(string $kind, int $entityId = 0): bool
    {
        return is_file(self::path($kind, $entityId));
    }

    /** Caminho da logo a usar, com herança de entidade-pai para o cliente. */
    public static function find(string $kind, int $entityId = 0): ?string
    {
        if ($kind === self::COMPANY) {
            return is_file(self::path($kind, 0)) ? self::path($kind, 0) : null;
        }

        foreach (self::chain($entityId) as $id) {
            if (is_file(self::path($kind, $id))) {
                return self::path($kind, $id);
            }
        }

        return null;
    }

    /** Logo pronta para embutir em <img src="...">. */
    public static function dataUri(string $kind, int $entityId = 0): ?string
    {
        $file = self::find($kind, $entityId);
        if ($file === null) {
            return null;
        }
        $bytes = @file_get_contents($file);

        return $bytes === false ? null : 'data:image/png;base64,' . base64_encode($bytes);
    }

    public static function remove(string $kind, int $entityId = 0): bool
    {
        return !self::hasOwn($kind, $entityId) || @unlink(self::path($kind, $entityId));
    }

    /**
     * Valida e salva o arquivo enviado (entrada de $_FILES['logo']).
     *
     * @return array{0: bool, 1: string} [sucesso, mensagem]
     */
    public static function save(string $kind, int $entityId, array $file, bool $checkUploaded = true): array
    {
        if (!self::isValidKind($kind)) {
            return [false, 'Tipo de logo inválido.'];
        }
        if (!function_exists('imagecreatefromstring')) {
            return [false, 'A extensão GD do PHP não está disponível.'];
        }

        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE) {
            return [false, 'Selecione um arquivo PNG ou JPEG.'];
        }
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            return [false, 'Arquivo maior que o limite permitido (1 MB).'];
        }
        if ($error !== UPLOAD_ERR_OK) {
            return [false, 'Falha no envio do arquivo (código ' . $error . ').'];
        }

        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($checkUploaded && !is_uploaded_file($tmp)) {
            return [false, 'Arquivo de envio inválido.'];
        }
        if ((int) ($file['size'] ?? 0) > self::MAX_BYTES) {
            return [false, 'Arquivo maior que 1 MB.'];
        }

        $bytes = @file_get_contents($tmp);
        $info  = $bytes === false ? false : @getimagesizefromstring($bytes);
        if ($info === false || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG], true)) {
            return [false, 'Formato não suportado. Envie uma imagem PNG ou JPEG.'];
        }
        if ($info[0] * $info[1] > self::MAX_PIXELS) {
            return [false, 'Imagem com dimensões grandes demais. Reduza e envie novamente.'];
        }

        $src = @imagecreatefromstring($bytes);
        if ($src === false) {
            return [false, 'Não foi possível ler a imagem.'];
        }

        // Reduz (nunca amplia) mantendo a proporção e preserva a transparência.
        $w     = imagesx($src);
        $h     = imagesy($src);
        $scale = min(1.0, self::MAX_W / $w, self::MAX_H / $h);
        $nw    = max(1, (int) round($w * $scale));
        $nh    = max(1, (int) round($h * $scale));

        $dst = imagecreatetruecolor($nw, $nh);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 255, 255, 255, 127));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);

        ob_start();
        imagepng($dst, null, 6);
        $png = (string) ob_get_clean();

        $dir = self::dir();
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return [false, 'Não foi possível criar a pasta da logo no servidor.'];
        }
        if (@file_put_contents(self::path($kind, $entityId), $png) === false) {
            return [false, 'Não foi possível gravar a logo (verifique as permissões de files/_plugins).'];
        }

        return [true, 'Logo salva.'];
    }
}
