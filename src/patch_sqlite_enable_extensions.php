<?php
/**
 * Enable dynamic loading of SQLite extensions via loadExtension()
 *
 * This patch removes the PHP_CHECK_LIBRARY tests that define OMIT flags
 * when the sqlite3_load_extension function is not found.
 *
 * Since we build SQLite with SQLITE_ENABLE_LOAD_EXTENSION=1, the function
 * should exist, but this provides defense-in-depth to ensure the OMIT flags
 * are never defined even if library detection has issues.
 */

$phpDir = SOURCE_PATH . '/php-src';

if (patch_point() === 'before-php-buildconf') {
    echo "[PATCH] Patching SQLite extension .m4 files to prevent OMIT flags...\n";

    // Patch ext/sqlite3/config0.m4
    $sqlite3ConfigFile = $phpDir . '/ext/sqlite3/config0.m4';
    if (file_exists($sqlite3ConfigFile)) {
        $content = file_get_contents($sqlite3ConfigFile);
        $originalContent = $content;

        // Remove the entire PHP_CHECK_LIBRARY block that defines SQLITE_OMIT_LOAD_EXTENSION
        $content = preg_replace(
            '/\s*PHP_CHECK_LIBRARY\s*\(\s*\[sqlite3\]\s*,\s*\[sqlite3_load_extension\]\s*,.*?\[\s*\$SQLITE3_SHARED_LIBADD\s*\]\s*\)/s',
            "\n  dnl Extension loading enabled - check removed",
            $content
        );

        if ($content !== $originalContent) {
            file_put_contents($sqlite3ConfigFile, $content);
            echo "[PATCH] ✓ ext/sqlite3/config0.m4 patched\n";
        } else {
            echo "[PATCH] ⚠ ext/sqlite3/config0.m4 - pattern not found\n";
        }
    }

    // Patch ext/pdo_sqlite/config.m4
    $pdoSqliteConfigFile = $phpDir . '/ext/pdo_sqlite/config.m4';
    if (file_exists($pdoSqliteConfigFile)) {
        $content = file_get_contents($pdoSqliteConfigFile);
        $originalContent = $content;

        // Replace PHP_SETUP_SQLITE with explicit paths to our compiled library
        // This ensures PDO uses the same library as sqlite3 extension
        $buildroot = BUILD_ROOT_PATH;
        $content = preg_replace(
            '/\s*PHP_SETUP_SQLITE\s*\(\s*\[PDO_SQLITE_SHARED_LIBADD\]\s*\)/',
            "\n  dnl Use our compiled SQLite library\n" .
            "  SQLITE_CFLAGS=\"-I{$buildroot}/include\"\n" .
            "  SQLITE_LIBS=\"-L{$buildroot}/lib -lsqlite3\"\n" .
            "  PHP_EVAL_INCLINE([\$SQLITE_CFLAGS])\n" .
            "  PHP_EVAL_LIBLINE([\$SQLITE_LIBS], [PDO_SQLITE_SHARED_LIBADD])",
            $content
        );

        // Remove the entire PHP_CHECK_LIBRARY block that defines PDO_SQLITE_OMIT_LOAD_EXTENSION
        $content = preg_replace(
            '/\s*PHP_CHECK_LIBRARY\s*\(\s*\[sqlite3\]\s*,\s*\[sqlite3_load_extension\]\s*,.*?\[\s*\$PDO_SQLITE_SHARED_LIBADD\s*\]\s*\)/s',
            "\n  dnl Extension loading enabled - check removed",
            $content
        );

        if ($content !== $originalContent) {
            file_put_contents($pdoSqliteConfigFile, $content);
            echo "[PATCH] ✓ ext/pdo_sqlite/config.m4 patched\n";
        } else {
            echo "[PATCH] ⚠ ext/pdo_sqlite/config.m4 - pattern not found\n";
        }
    }

    echo "[PATCH] Done patching .m4 files\n";
}
