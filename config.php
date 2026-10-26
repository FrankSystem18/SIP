<?php
/**
 * Configuración principal de la aplicación.
 * Este archivo debe requerirse desde la raíz del proyecto.
 */

// ============================================================
// CAPA 1 — Parámetros seguros de sesión (antes de session_start)
// Se refuerzan via ini_set para garantizar que apliquen
// independientemente de php.ini del servidor.
// ============================================================
ini_set('session.cookie_httponly', '1');   // Bloquea acceso JS a la cookie
ini_set('session.cookie_samesite', 'Lax'); // Protege contra CSRF de terceros
// Activar 'Secure' en HTTPS; detectamos el protocolo para no romper
// el entorno local (HTTP en XAMPP).
$_sipSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
              || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
ini_set('session.cookie_secure', $_sipSecure ? '1' : '0');
unset($_sipSecure);

// ============================================================
// CAPA 2 — Cabeceras HTTP de seguridad globales
// Se envían en cada respuesta para proteger el navegador.
// ============================================================
if (!headers_sent()) {
    // Previene que la página sea incrustada en iframes de dominios ajenos
    header('X-Frame-Options: SAMEORIGIN');

    // Impide que el navegador haga MIME-sniffing del Content-Type
    header('X-Content-Type-Options: nosniff');

    // Política de seguridad de contenido: permite recursos propios y HTTPS
    header("Content-Security-Policy: default-src 'self' https:; style-src 'self' 'unsafe-inline' https:; script-src 'self' https:;");
}


if (!defined('BASE_URL')) {
    $envUrl = getenv('BASE_URL');
    if ($envUrl && $envUrl !== false) {
        define('BASE_URL', rtrim($envUrl, '/') . '/');
    } else {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

        $projectRoot = rtrim(str_replace('\\', '/', realpath(__DIR__) ?: __DIR__), '/');
        $docRoot     = !empty($_SERVER['DOCUMENT_ROOT']) ? rtrim(str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT']) ?: $_SERVER['DOCUMENT_ROOT']), '/') : '';
        $scriptName  = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? ($_SERVER['PHP_SELF'] ?? ''));
        $scriptFile  = !empty($_SERVER['SCRIPT_FILENAME']) ? str_replace('\\', '/', realpath($_SERVER['SCRIPT_FILENAME']) ?: $_SERVER['SCRIPT_FILENAME']) : '';

        $basePath = '';
        if ($docRoot && stripos($projectRoot, $docRoot) === 0) {
            $basePath = substr($projectRoot, strlen($docRoot));
        } elseif ($scriptFile && $projectRoot && stripos($scriptFile, $projectRoot) === 0) {
            $relScript = substr($scriptFile, strlen($projectRoot));
            if ($relScript !== '' && (substr($scriptName, -strlen($relScript)) === $relScript)) {
                $basePath = substr($scriptName, 0, -strlen($relScript));
            }
        } else {
            $folderName = '/' . basename($projectRoot);
            $pos = stripos($scriptName, $folderName);
            if ($pos !== false) {
                $basePath = substr($scriptName, 0, $pos + strlen($folderName));
            }
        }

        $basePath = '/' . trim($basePath, '/');
        if ($basePath === '/') {
            $basePath = '';
        }

        define('BASE_URL', $scheme . '://' . $host . $basePath . '/');
    }
}

if (!defined('APP_TIMEZONE')) {
    define('APP_TIMEZONE', 'America/Caracas');
}

date_default_timezone_set(APP_TIMEZONE);

// Autoloader PSR-4 nativo — carga automática de modelos e includes
require_once __DIR__ . '/includes/autoloader.php';

// Logger de archivo — disponible en toda la aplicación
require_once __DIR__ . '/includes/Logger.php';

require_once __DIR__ . '/config/database.php';

