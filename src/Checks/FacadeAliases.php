<?php

namespace Imanghafoori\LaravelMicroscope\Checks;

use Illuminate\Foundation\AliasLoader;
use Imanghafoori\Filesystem\Filesystem;
use Imanghafoori\LaravelMicroscope\FileReaders\FilePath;
use Imanghafoori\SearchReplace\Searcher;
use Imanghafoori\TokenAnalyzer\Refactor;

class FacadeAliases
{
    public static $command;

    public static function check($tokens, $absFilePath, $classFilePath, $psr4Path, $psr4Namespace, $imports)
    {
        $aliases = AliasLoader::getInstance()->getAliases();
        $isReplaced = false;

        foreach ($imports as $import) {
            foreach ($import as $base => $use) {
                if (! isset($aliases[$use[0]]) or ! self::ask($absFilePath, $use, $base, $aliases[$use[0]])) {
                    continue;
                }
                $isReplaced = true;
                $newVersion = self::searchReplace($base, $aliases[$use[0]], $tokens);

                Filesystem::$fileSystem::file_put_contents($absFilePath, Refactor::toString($newVersion));

                $tokens = token_get_all(Filesystem::$fileSystem::file_get_contents($absFilePath));
            }
        }

        if ($isReplaced) {
            return $tokens;
        }
    }

    private static function ask($absFilePath, $use, $base, $aliases)
    {
        $relativePath = FilePath::normalize(\trim(\str_replace(base_path(), '', $absFilePath), '\\/'));
        self::$command->getOutput()->writeln('at '.$relativePath.':'.$use[1]);
        $question = 'Do you want to replace <fg=yellow>'.$base.'</> with <fg=yellow>'.$aliases.'</>';

        return self::$command->confirm($question, true);
    }

    private static function searchReplace($base, $aliases, $tokens)
    {
        $patterns = [
            [
                ['search' => 'use '.$base.';', 'replace' => 'use '.ltrim($aliases).';'],
            ],
            [
                ['search' => 'use \\'.$base.';', 'replace' => 'use '.ltrim($aliases).';'],
            ],
            [
                ['search' => 'use '.$base.',', 'replace' => 'use '.ltrim($aliases).';'.PHP_EOL.'use '],
                ['search' => 'use \\'.$base.',', 'replace' => 'use '.ltrim($aliases).';'.PHP_EOL.'use '],
            ],
            [
                ['search' => ','.$base.';', 'replace' => ', '.ltrim($aliases).';'],
                ['search' => ',\\'.$base.';', 'replace' => ', '.ltrim($aliases).';'],
            ],
            [
                ['search' => ','.$base.',', 'replace' => '; '.PHP_EOL.'use '.ltrim($aliases).';'.PHP_EOL.'use '],
                ['search' => ',\\'.$base.',', 'replace' => '; '.PHP_EOL.'use '.ltrim($aliases).';'.PHP_EOL.'use '],
            ],
        ];
        $lines = false;
        $newVersion = null;
        foreach ($patterns as $pattern) {
            if ($lines) {
                break;
            }
            [$newVersion, $lines] = Searcher::search($pattern, $tokens, 1);
        }

        return $newVersion;
    }
}
