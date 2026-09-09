<?php
/**
 * Minimal .env loader — no Composer required.
 *
 * Reads KEY=VALUE pairs from a .env file and loads them into
 * getenv(), $_ENV, and $_SERVER.  Existing env vars are NOT
 * overwritten (real env wins over .env defaults).
 *
 * Path precedence (First-loaded file wins; real env always wins):
 *   1. Real environment (set before we run — never overwritten).
 *   2. /etc/avscms/.env   (production VM secrets dir, outside webroot)
 *   3. <project-root>/.env (local dev)
 *
 * Usage:  require __DIR__ . '/dotenv.php';
 *         // or with a custom path:
 *         dotenv_load('/path/to/.env');
 */
if (!function_exists('dotenv_load')) {
    function dotenv_load($paths = null)
    {
        if ($paths === null) {
            // Production secrets dir first (higher precedence), then the
            // project-root .env that lives in the webroot (local dev).
            $paths = array('/etc/avscms/.env', dirname(__DIR__) . '/.env');
        } elseif (is_string($paths)) {
            $paths = array($paths);
        }

        $loaded = false;
        foreach ($paths as $path) {
            if (!is_string($path) || !is_file($path) || !is_readable($path)) {
                continue;
            }
            $loaded = true;

            $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($lines === false) {
                continue;
            }

            foreach ($lines as $line) {
                $line = trim($line);

                // Skip comments and blank lines.
                if ($line === '' || $line[0] === '#') {
                    continue;
                }

                // Split on the first '=' only.
                $pos = strpos($line, '=');
                if ($pos === false) {
                    continue;
                }

                $key   = trim(substr($line, 0, $pos));
                $value = trim(substr($line, $pos + 1));

                // Strip surrounding quotes (single or double).
                if (strlen($value) >= 2
                    && (($value[0] === '"' && substr($value, -1) === '"')
                        || ($value[0] === "'" && substr($value, -1) === "'"))
                ) {
                    $value = substr($value, 1, -1);
                }

                // Expand ${VAR} and $VAR references inside double-quoted values.
                $value = preg_replace_callback('/\$\{?([A-Za-z_][A-Za-z0-9_]*)\}?/', function ($m) {
                    $env = getenv($m[1]);
                    return $env !== false ? $env : $m[0];
                }, $value);

                // Don't overwrite a real environment variable.
                if (getenv($key) !== false) {
                    continue;
                }

                putenv("$key=$value");
                $_ENV[$key]   = $value;
                $_SERVER[$key] = $value;
            }
        }

        return $loaded;
    }
}

// Auto-load: production secrets dir + project root .env.
dotenv_load();
