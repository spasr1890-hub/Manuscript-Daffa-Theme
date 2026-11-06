<?php

/**
 * @file plugins/themes/manuscriptDaffa/tests/smarty-filter.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * With the Smarty version of OJS 3.5 (4.5.x): an output filter registered
 * without a name is replaced by another closure filter (both are named
 * "closure"), while a named filter runs next to it. The theme registers its
 * filter as "manuscriptDaffa" (see run.php). Run:
 *
 *   php tests/smarty-filter.php /path/to/smarty/libs
 */

$libs = rtrim($argv[1] ?? '', '/');
if (!is_file($libs . '/Smarty.class.php')) {
    fwrite(STDERR, "Usage: php tests/smarty-filter.php /path/to/smarty/libs\n");
    exit(2);
}
require $libs . '/Smarty.class.php';

$dir = sys_get_temp_dir() . '/msd-smarty-' . getmypid();
@mkdir($dir . '/tpl', 0777, true);
@mkdir($dir . '/compiled', 0777, true);
file_put_contents($dir . '/tpl/page.tpl', '<html><body><ul class="authors"><li>A</li></ul></body></html>');

function render(bool $named, string $dir): string
{
    $smarty = new Smarty();
    $smarty->setTemplateDir($dir . '/tpl');
    $smarty->setCompileDir($dir . '/compiled');
    $theme = fn ($out, $tpl) => str_replace('</li>', ' [theme]</li>', $out);
    $otherPlugin = fn ($out, $tpl) => str_replace('<ul class="authors">', '<ul id="author-list" class="authors">', $out);
    $named ? $smarty->registerFilter('output', $theme, 'manuscriptDaffa') : $smarty->registerFilter('output', $theme);
    $smarty->registerFilter('output', $otherPlugin);
    ob_start();
    $smarty->display('page.tpl');
    return ob_get_clean();
}

$unnamed = render(false, $dir);
$named = render(true, $dir);
array_map('unlink', glob($dir . '/compiled/*') ?: []);

$ok = !str_contains($unnamed, '[theme]')            // the clash this guards against
    && str_contains($named, '[theme]') && str_contains($named, 'author-list');
echo 'Smarty ' . Smarty::SMARTY_VERSION . "\n";
echo 'unnamed theme filter: ' . (str_contains($unnamed, '[theme]') ? 'ran' : 'replaced by the other closure') . "\n";
echo 'named theme filter:   ' . (str_contains($named, '[theme]') ? 'ran' : 'did NOT run') . ', other plugin filter: ' . (str_contains($named, 'author-list') ? 'ran' : 'did NOT run') . "\n";
echo $ok ? "ok\n" : "FAIL\n";
exit($ok ? 0 : 1);
