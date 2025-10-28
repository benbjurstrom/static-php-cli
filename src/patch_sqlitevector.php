<?php
// patch_sqlitevector.php – works with static-php-cli ≥ 2.0, cross-platform (Linux/macOS)
use SPC\store\FileSystem as FS;

$vectorDir = __DIR__ . '/sqlite-vector/src';  // where sqlite-vector sources live (in src/ subdir)
$phpDir = SOURCE_PATH . '/php-src';           // root of extracted php-src
$sqliteExtDir = $phpDir . '/ext/sqlite3';

// 1) Create core_init.c and copy sqlite-vector sources
if (patch_point() === 'after-php-extract') {
    $coreInit = <<<'C'
#define SQLITE_CORE 1
#include "sqlite3.h"
#include "sqlite-vector.h"
#include <stdio.h>

int core_init(const char *dummy) {
    return sqlite3_auto_extension((void *)sqlite3_vector_init);
}
C;

    file_put_contents($sqliteExtDir . '/core_init.c', $coreInit);

    // Copy all sqlite-vector source files
    $sourceFiles = [
        'sqlite-vector.c',
        'sqlite-vector.h',
        'distance-cpu.c',
        'distance-cpu.h',
        'distance-sse2.c',
        'distance-sse2.h',
        'distance-avx2.c',
        'distance-avx2.h',
        'distance-neon.c',
        'distance-neon.h',
    ];

    foreach ($sourceFiles as $file) {
        $srcPath = $vectorDir . '/' . $file;
        $destPath = $sqliteExtDir . '/' . $file;

        if (!file_exists($srcPath)) {
            echo "Warning: $srcPath not found, skipping\n";
            continue;
        }

        copy($srcPath, $destPath);
    }

    // Copy fp16 header files (required dependency)
    $fp16Dir = dirname($vectorDir) . '/libs/fp16';
    $fp16DestDir = $sqliteExtDir . '/fp16';

    if (!is_dir($fp16DestDir)) {
        mkdir($fp16DestDir, 0755, true);
    }

    $fp16Files = ['fp16.h', 'macros.h', 'bitcasts.h'];
    foreach ($fp16Files as $file) {
        $srcPath = $fp16Dir . '/' . $file;
        $destPath = $fp16DestDir . '/' . $file;

        if (!file_exists($srcPath)) {
            echo "Warning: $srcPath not found, skipping\n";
            continue;
        }

        copy($srcPath, $destPath);
    }

    // Clean up any stale object files from previous builds
    $objectFiles = [
        'core_init.o', 'core_init.lo',
        'sqlite-vector.o', 'sqlite-vector.lo',
        'distance-cpu.o', 'distance-cpu.lo',
        'distance-sse2.o', 'distance-sse2.lo',
        'distance-avx2.o', 'distance-avx2.lo',
        'distance-neon.o', 'distance-neon.lo',
    ];

    foreach ($objectFiles as $objFile) {
        @unlink($sqliteExtDir . '/' . $objFile);
    }
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

  dnl ---- sqlite-vector static compile ----
  AC_MSG_NOTICE([Building sqlite3 with sqlite-vector support])
  AC_DEFINE([SQLITE_CORE], [1], [Build sqlite-vector as builtin])
  AC_DEFINE([SQLITE_VECTOR_STATIC], [1], [Static sqlite-vector])

  dnl Architecture-specific SIMD optimizations
  case "$host_cpu" in
    aarch64*|arm64*)
      AC_MSG_NOTICE([Enabling NEON SIMD optimizations for ARM64])
      dnl NEON is always available on aarch64, compiler defines __ARM_NEON__ automatically
      dnl Add explicit march flag to ensure optimal code generation
      CFLAGS="$CFLAGS -march=armv8-a"
      ;;
    x86_64*|amd64*|i?86*)
      AC_MSG_NOTICE([Enabling SSE2 SIMD optimizations for x86_64])
      dnl Enable SSE2 (baseline for x86_64)
      dnl Note: AVX2 code is NOT compiled to maintain binary compatibility
      dnl To enable AVX2, add "-mavx2 -mfma" to CFLAGS (requires Haswell 2013+ CPUs)
      CFLAGS="$CFLAGS -msse2"
      ;;
    *)
      AC_MSG_NOTICE([Using CPU fallback for architecture: $host_cpu])
      ;;
  esac

  PHP_NEW_EXTENSION([sqlite3],
    [sqlite3.c sqlite-vector.c distance-cpu.c distance-sse2.c distance-avx2.c distance-neon.c core_init.c],
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

    // Ensure fp16 directory exists (in case it was cleaned)
    $fp16Dir = $sqliteExtDir . '/fp16';
    if (!is_dir($fp16Dir)) {
        mkdir($fp16Dir, 0755, true);
    }
}

// 3) Hook the extension in MINIT just before make (backup registration)
if (patch_point() === 'before-php-make') {
    // Final cleanup of any stale object files before compile
    $objectFiles = [
        'core_init.o', 'core_init.lo',
        'sqlite-vector.o', 'sqlite-vector.lo',
        'distance-cpu.o', 'distance-cpu.lo',
        'distance-sse2.o', 'distance-sse2.lo',
        'distance-avx2.o', 'distance-avx2.lo',
        'distance-neon.o', 'distance-neon.lo',
    ];

    foreach ($objectFiles as $objFile) {
        @unlink($sqliteExtDir . '/' . $objFile);
    }

    $cfile = $sqliteExtDir . '/sqlite3.c';
    FS::replaceFileUser($cfile, function ($code) {
        if (str_contains($code, 'sqlite3_vector_init')) return $code;      // already patched
        $inject = "\n    extern int sqlite3_vector_init(sqlite3*,char**,const sqlite3_api_routines*);\n"
                . "    sqlite3_auto_extension((void(*)(void))sqlite3_vector_init);\n";
        return preg_replace('/PHP_MINIT_FUNCTION\(sqlite3\)\s*\{/', '$0' . $inject, $code, 1);
    });
}
