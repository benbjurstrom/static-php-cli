<?php

declare(strict_types=1);

namespace SPC\builder\extension;

use SPC\builder\Extension;
use SPC\store\FileSystem;
use SPC\util\CustomExt;

#[CustomExt('pdo_sqlite')]
class pdo_sqlite extends Extension
{
    public function patchBeforeConfigure(): bool
    {
        FileSystem::replaceFileRegex(
            SOURCE_PATH . '/php-src/configure',
            '/sqlite3_column_table_name=yes/',
            'sqlite3_column_table_name=no'
        );

        // Force PDO SQLite to use the same SQLite library as sqlite3 extension
        // This ensures PDO gets the version we compiled with SQLITE_ENABLE_LOAD_EXTENSION=1
        // instead of finding a system library via pkg-config
        $buildroot = BUILD_ROOT_PATH;
        FileSystem::replaceFileRegex(
            SOURCE_PATH . '/php-src/configure',
            '/PHP_SETUP_SQLITE\(\[PDO_SQLITE_SHARED_LIBADD\]\)/',
            "dnl Force PDO to use our compiled SQLite library\n  " .
            "SQLITE_CFLAGS=\"-I{$buildroot}/include\"\n  " .
            "SQLITE_LIBS=\"-L{$buildroot}/lib -lsqlite3\"\n  " .
            "PHP_EVAL_INCLINE([\$SQLITE_CFLAGS])\n  " .
            "PHP_EVAL_LIBLINE([\$SQLITE_LIBS], [PDO_SQLITE_SHARED_LIBADD])"
        );

        return true;
    }
}
