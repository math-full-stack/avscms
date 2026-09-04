<?php
// Allow CLI execution (defined in config.php when included by the app)
if (php_sapi_name() !== 'cli') {
    defined('_VALID') or die('Restricted Access!');
}

/**
 * Database migration system for AVSCMS.
 *
 * Tracks applied migrations in a `db_migrations` table and runs only the
 * ones that have not been applied yet.  Every .sql file inside sql/migrations/
 * is executed in filename-sort order the first time the system encounters it.
 *
 * Conventions:
 *   - Files must end with .sql
 *   - Files are executed in alphabetical sort order (prefix with date or number)
 *   - Each file should be idempotent (safe to re-run)
 */

define('MIGRATIONS_DIR', dirname(__DIR__) . '/sql/migrations');

/**
 * Ensure the tracking table exists.
 */
function migrations_ensure_table(mysqli $db): void
{
    $db->query("
        CREATE TABLE IF NOT EXISTS `db_migrations` (
            `id`         int(11) unsigned NOT NULL AUTO_INCREMENT,
            `filename`   varchar(255) NOT NULL DEFAULT '',
            `applied_at` int(11) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_migration_filename` (`filename`)
        ) ENGINE=MyISAM DEFAULT CHARSET=utf8
    ");
}

/**
 * Return a sorted list of .sql filenames from the migrations directory.
 */
function migrations_list_files(): array
{
    if (!is_dir(MIGRATIONS_DIR)) {
        return [];
    }
    $files = glob(MIGRATIONS_DIR . '/*.sql');
    if (!is_array($files)) {
        return [];
    }
    $names = array_map('basename', $files);
    sort($names, SORT_STRING);
    return $names;
}

/**
 * Return filenames already applied (from the tracking table).
 */
function migrations_applied(mysqli $db): array
{
    $res = $db->query("SELECT `filename` FROM `db_migrations` ORDER BY `applied_at`");
    $applied = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $applied[] = $row['filename'];
        }
        $res->free();
    }
    return $applied;
}

/**
 * Mark a migration as applied.
 */
function migrations_mark_applied(mysqli $db, string $filename): void
{
    $escaped = $db->real_escape_string($filename);
    $db->query("INSERT IGNORE INTO `db_migrations` (`filename`,`applied_at`) VALUES ('{$escaped}', UNIX_TIMESTAMP())");
}

/**
 * Run all pending migrations.
 *
 * Returns an array of [filename => 'OK'|'SKIPPED'|'ERROR: ...'] for logging.
 */
function migrations_run(mysqli $db): array
{
    migrations_ensure_table($db);

    $all_files = migrations_list_files();
    $applied   = migrations_applied($db);
    $pending   = array_diff($all_files, $applied);

    $results = [];

    foreach ($pending as $filename) {
        $filepath = MIGRATIONS_DIR . '/' . $filename;
        if (!is_file($filepath)) {
            $results[$filename] = 'SKIPPED (file missing)';
            continue;
        }

        $sql = file_get_contents($filepath);
        if ($sql === false) {
            $results[$filename] = 'ERROR: could not read file';
            continue;
        }

        // Skip pure comments / empty files
        $trimmed = trim(preg_replace('/--.*$/m', '', $sql));
        if ($trimmed === '') {
            migrations_mark_applied($db, $filename);
            $results[$filename] = 'OK (empty/comments only)';
            continue;
        }

        // Execute the SQL — consume all result sets to avoid "commands out of sync"
        $had_error = false;
        $error_msg = '';

        if ($db->multi_query($sql)) {
            do {
                if ($res = $db->store_result()) {
                    $res->free();
                }
                // Check error after each result set, suppress next_result() warnings
                if ($db->errno) {
                    $had_error = true;
                    $error_msg = $db->error;
                }
            } while (@$db->more_results() && @$db->next_result());

            // Final error check
            if ($db->errno) {
                $had_error = true;
                $error_msg = $db->error;
            }
        } else {
            $had_error = true;
            $error_msg = $db->error;
        }

        if ($had_error) {
            $results[$filename] = 'ERROR: ' . $error_msg;
        } else {
            migrations_mark_applied($db, $filename);
            $results[$filename] = 'OK';
        }
    }

    return $results;
}

/**
 * CLI entry point: run from shell with  php include/function_migrations.php
 */
if (php_sapi_name() === 'cli' && realpath($argv[0] ?? '') === realpath(__FILE__)) {
    $db_host = getenv('DB_HOST') ?: 'localhost';
    $db_user = getenv('DB_USER') ?: 'root';
    $db_pass = getenv('DB_PASS') ?: '';
    $db_name = getenv('DB_NAME') ?: 'avs';

    $db = new mysqli($db_host, $db_user, $db_pass, $db_name);
    if ($db->connect_error) {
        fwrite(STDERR, "DB connection failed: " . $db->connect_error . "\n");
        exit(1);
    }
    $db->set_charset('utf8');

    $results = migrations_run($db);

    if (empty($results)) {
        echo "No pending migrations.\n";
    } else {
        foreach ($results as $file => $status) {
            echo "  {$status}  {$file}\n";
        }
    }

    $db->close();
    exit(0);
}
