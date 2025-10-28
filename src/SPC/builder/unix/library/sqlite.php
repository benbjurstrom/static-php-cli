<?php

declare(strict_types=1);

namespace SPC\builder\unix\library;

use SPC\util\executor\UnixAutoconfExecutor;

trait sqlite
{
    protected function build(): void
    {
        // Enable SQLite extension loading by setting CFLAGS
        // This is critical for PDO's load_extension() to work
        UnixAutoconfExecutor::create($this)
            ->appendEnv([
                'CFLAGS' => '-DSQLITE_ENABLE_LOAD_EXTENSION=1'
            ])
            ->configure()
            ->make();
        $this->patchPkgconfPrefix(['sqlite3.pc']);
    }
}
