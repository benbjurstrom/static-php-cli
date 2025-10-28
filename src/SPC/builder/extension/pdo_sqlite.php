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

        // Defense-in-depth: Also patch the generated configure script
        // to ensure PDO uses our compiled SQLite library
        // This is a backup in case the .m4 file patching doesn't work
        $configureFile = SOURCE_PATH . '/php-src/configure';
        if (file_exists($configureFile)) {
            $content = file_get_contents($configureFile);

            // Look for the PDO_SQLITE_OMIT_LOAD_EXTENSION definition and remove it
            // This appears in the configure script if sqlite3_load_extension is not found
            $content = preg_replace(
                '/\$as_echo "#define PDO_SQLITE_OMIT_LOAD_EXTENSION 1" >>confdefs\.h/',
                '# PDO_SQLITE_OMIT_LOAD_EXTENSION check removed by patch',
                $content
            );

            file_put_contents($configureFile, $content);
            logger()->info('Patched configure script to remove PDO_SQLITE_OMIT_LOAD_EXTENSION');
        }

        return true;
    }
}
