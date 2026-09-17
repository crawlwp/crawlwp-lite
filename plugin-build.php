<?php
$dir               = @$argv[1] ?: dirname(__FILE__) . '/build';
$plugin_git_name   = 'mihdan-index-now';
$plugin_git_folder = $dir . '/' . $plugin_git_name;

function deleteDir($path)
{
    if (PHP_OS === 'Windows') {
        exec("rd /s /q {$path}");
    } else {
        exec("rm -rf {$path}");
    }
}

chdir($dir);

if (file_exists($plugin_git_folder)) {
    deleteDir($plugin_git_folder);
}

deleteDir($plugin_git_folder . '.zip');

exec("git clone -b seo-meta https://github.com/crawlwp/crawlwp-lite.git $plugin_git_name");
$chdir = chdir($plugin_git_folder);
if ( ! $chdir) exit;

exec('composer install --no-dev --no-progress --no-suggest');
exec('composer prefix-dependencies');

/**
 * Hard release gate: leftover debug helpers must never ship.
 *
 * A stray ray()/dd()/var_dump() call fatals on any site that does not have the
 * matching debug package installed, so the build refuses to package one.
 *
 * @param string $path Directory to scan.
 *
 * @return string[] "file:line: snippet" for every offending call.
 */
function findDebugCalls($path)
{
    $offenders = [];
    $pattern   = '/(?<![\w\$>:\\\\])(ray|dd|dump|var_dump|xdebug_break|debug_zval_dump)\s*\(/i';

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path));

    foreach ($files as $file) {
        if ( ! $file->isFile() || strtolower($file->getExtension()) !== 'php') {
            continue;
        }

        $lines = file($file->getPathname());

        foreach ($lines as $number => $line) {
            if (preg_match($pattern, $line)) {
                $offenders[] = $file->getPathname() . ':' . ($number + 1) . ': ' . trim($line);
            }
        }
    }

    return $offenders;
}

$debug_calls = findDebugCalls($plugin_git_folder . '/src');

if ( ! empty($debug_calls)) {
    fwrite(STDERR, "Build aborted — debug calls found:\n" . implode("\n", $debug_calls) . "\n");
    exit(1);
}

deleteDir('.git');
deleteDir('.github');
deleteDir('.wordpress-org');
deleteDir('php-scoper');
deleteDir('codekit');
deleteDir('tests');
deleteDir('doc');

deleteDir('vendor');

foreach (
    array(
        '.gitignore',
        '.distignore',
        '.editorconfig',
        'scoper.inc.php',
        'composer.json',
        'README.md',
        'AGENTS.md',
        'plugin-build.php',
        'composer.lock',
        'phpstan.neon',
        'phpstan-baseline.neon',
        'phpunit.xml.dist',
        '.phpunit.result.cache',
        'mihdan-index-now.zip',
    ) as $file
) {
    @unlink($file);
}

// move up directory
chdir($dir);
exec("7zz a mihdan-index-now.zip mihdan-index-now/");
deleteDir($plugin_git_name);

