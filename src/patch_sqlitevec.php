<?php
// patch_sqlitevec.php – works with static-php-cli ≥ 2.0, cross-platform (Linux/macOS)
use SPC\store\FileSystem as FS;

$vecDir = __DIR__ . '/sqlite-vec';           // where sqlite-vec.c/h live
$phpDir = SOURCE_PATH . '/php-src';          // root of extracted php-src
$sqliteExtDir = $phpDir . '/ext/sqlite3';

// 1) Create core_init.c and copy sqlite-vec sources
if (patch_point() === 'after-php-extract') {
    $coreInit = <<<'C'
#define SQLITE_CORE 1
#include "sqlite3.h"
#include "sqlite-vec.h"
#include <stdio.h>

int core_init(const char *dummy) {
    return sqlite3_auto_extension((void *)sqlite3_vec_init);
}
C;

    file_put_contents($sqliteExtDir . '/core_init.c', $coreInit);

    $vecC = file_get_contents($vecDir . '/sqlite-vec.c');
    $vecH = file_get_contents($vecDir . '/sqlite-vec.h');

    // musl typedef fix: flip any bogus typedefs the amalgamation may have
    $vecC = preg_replace('/typedef\s+u_int8_t\s+uint8_t\s*;/',  'typedef uint8_t  u_int8_t;',  $vecC);
    $vecC = preg_replace('/typedef\s+u_int16_t\s+uint16_t\s*;/', 'typedef uint16_t u_int16_t;', $vecC);
    $vecC = preg_replace('/typedef\s+u_int64_t\s+uint64_t\s*;/', 'typedef uint64_t u_int64_t;', $vecC);

    // Zend macro collision: rename all array_init(…) to avoid zend_API.h macro
    $vecC = preg_replace('/\barray_init\s*\(/', 'sqlitevec_array_init(', $vecC);

    file_put_contents($sqliteExtDir . '/sqlite-vec.c', $vecC);
    file_put_contents($sqliteExtDir . '/sqlite-vec.h', $vecH);

    // Clean up any stale object files from previous builds
    @unlink($sqliteExtDir . '/core_init.o');
    @unlink($sqliteExtDir . '/core_init.lo');
    @unlink($sqliteExtDir . '/sqlite-vec.o');
    @unlink($sqliteExtDir . '/sqlite-vec.lo');
}

// 2) Overwrite BOTH config0.m4 and config.m4 before buildconf (critical: before configure script is generated)
if (patch_point() === 'before-php-buildconf') {
    $workingM4 = <<<'M4'
PHP_ARG_WITH([sqlite3],
  [whether to enable the SQLite3 extension],
  [AS_HELP_STRING([--without-sqlite3],
    [Do not include SQLite3 support.])],
  [yes])

if test $PHP_SQLITE3 != "no"; then
  PHP_SETUP_SQLITE([SQLITE3_SHARED_LIBADD])
  AC_DEFINE([HAVE_SQLITE3], [1],
    [Define to 1 if the PHP extension 'sqlite3' is available.])

  PHP_CHECK_LIBRARY([sqlite3], [sqlite3_errstr],
    [AC_DEFINE([HAVE_SQLITE3_ERRSTR], [1],
      [Define to 1 if SQLite library has the 'sqlite3_errstr' function.])],
    [],
    [$SQLITE3_SHARED_LIBADD])

  PHP_CHECK_LIBRARY([sqlite3], [sqlite3_expanded_sql],
    [AC_DEFINE([HAVE_SQLITE3_EXPANDED_SQL], [1],
      [Define to 1 if SQLite library has the 'sqlite3_expanded_sql' function.])],
    [],
    [$SQLITE3_SHARED_LIBADD])

  PHP_CHECK_LIBRARY([sqlite3], [sqlite3_load_extension],
    [],
    [AC_DEFINE([SQLITE_OMIT_LOAD_EXTENSION], [1],
      [Define to 1 if SQLite library was compiled with the
      SQLITE_OMIT_LOAD_EXTENSION and does not have the extension support with
      the 'sqlite3_load_extension' function. For usage in the sqlite3 PHP
      extension. See https://www.sqlite.org/compile.html.])],
    [$SQLITE3_SHARED_LIBADD])

  dnl ---- sqlite-vec static compile ----
  AC_MSG_NOTICE([Building sqlite3 with sqlite-vec support])
  AC_DEFINE([SQLITE_ENABLE_VEC0], [1], [Enable sqlite-vec virtual table])
  AC_DEFINE([SQLITE_CORE], [1], [Build sqlite-vec as builtin])
  AC_DEFINE([SQLITE_VEC_STATIC], [1], [Static sqlite-vec])

  PHP_NEW_EXTENSION([sqlite3],
    [sqlite3.c sqlite-vec.c core_init.c],
    [$ext_shared],,
    [-DZEND_ENABLE_STATIC_TSRMLS_CACHE=1])
  PHP_SUBST([SQLITE3_SHARED_LIBADD])
fi
M4;

    // Write ONLY config0.m4 and let PHP's build system handle config.m4
    file_put_contents($sqliteExtDir . '/config0.m4', $workingM4);

    // Delete config.m4 if it exists to avoid dual processing
    @unlink($sqliteExtDir . '/config.m4');
    @unlink($sqliteExtDir . '/Makefile.frag');
    @unlink($sqliteExtDir . '/Makefile.objects');
    @unlink($phpDir . '/Makefile');
}

// 3) Hook the extension in MINIT just before make (backup registration)
if (patch_point() === 'before-php-make') {
    // Final cleanup of any stale object files before compile
    @unlink($sqliteExtDir . '/core_init.o');
    @unlink($sqliteExtDir . '/core_init.lo');
    @unlink($sqliteExtDir . '/sqlite-vec.o');
    @unlink($sqliteExtDir . '/sqlite-vec.lo');

    $cfile = $sqliteExtDir . '/sqlite3.c';
    FS::replaceFileUser($cfile, function ($code) {
        if (str_contains($code, 'sqlite3_vec_init')) return $code;      // already patched
        $inject = "\n    extern int sqlite3_vec_init(sqlite3*,char**,const sqlite3_api_routines*);\n"
                . "    sqlite3_auto_extension((void(*)(void))sqlite3_vec_init);\n";
        return preg_replace('/PHP_MINIT_FUNCTION\(sqlite3\)\s*\{/', '$0' . $inject, $code, 1);
    });
}
