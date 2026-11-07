<?php

/**
 * @file plugins/themes/manuscriptDaffa/tests/check-templates.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * Checks that the OJS templates still contain the markup the theme's HTML
 * filters look for. Run against an OJS checkout (with lib/pkp):
 *
 *   php tests/check-templates.php /path/to/ojs            (OJS 3.4/3.5)
 *   php tests/check-templates.php /path/to/ojs --ojs33    (OJS 3.3 build)
 *
 * A missing marker means that a feature would silently do nothing on that
 * OJS version. Exits with status 1 when a marker is missing.
 */

$ojs = rtrim($argv[1] ?? '', '/');
$ojs33 = in_array('--ojs33', $argv, true);
if ($ojs === '' || !is_dir($ojs . '/templates') || !is_dir($ojs . '/lib/pkp/templates')) {
    fwrite(STDERR, "Usage: php tests/check-templates.php /path/to/ojs (with lib/pkp)\n");
    exit(2);
}

// template => [marker => feature that needs it]
$markers = [
    'templates/frontend/objects/article_details.tpl' => [
        '<section class="item authors">' => 'author countries',
        'class="authors"' => 'author countries',
        '<span class="name">' => 'author countries',
        '<span class="affiliation">' => 'author countries',
        '{foreach from=$publication->getData(\'authors\') item=author}' => 'author countries (author order)',
        '<section class="item doi">' => 'Dimensions badge',
    ],
    'templates/frontend/objects/article_summary.tpl' => [
        '<div class="obj_article_summary">' => 'action rows, homepage fold',
        '<a id="article-{$article->getId()}"' => 'action rows, homepage fold',
        '<div class="meta">' => 'action rows (position)',
        '<div class="authors">' => 'action rows (position)',
        'value="article-{$article->getId()}-galley-{$galley->getId()}"' => 'action rows (PDF button)',
    ],
    'templates/frontend/objects/galley_link.tpl' => [
        'obj_galley_link{/if} {$type|escape}' => 'action rows (PDF button class)',
        '{if $id} id="{$id}"{/if}' => 'action rows (PDF button id)',
    ],
    'templates/frontend/objects/issue_toc.tpl' => [
        '<div class="obj_issue_toc">' => 'issue coverage',
        '<div class="heading">' => 'issue coverage',
        '<div class="cover">' => 'issue coverage (next to the cover)',
        '<div class="sections">' => 'homepage fold',
        '</div><!-- .sections -->' => 'homepage fold',
        '<div class="section">' => 'homepage fold',
        '<ul class="cmp_article_list articles">' => 'homepage fold',
    ],
    'templates/frontend/pages/indexJournal.tpl' => [
        '<div class="page_index_journal">' => 'category cards',
        '<section class="homepage_about">' => 'category cards (under About)',
        '<section class="current_issue">' => 'category cards, homepage fold',
        '</div><!-- .page -->' => 'category cards',
    ],
    'templates/frontend/objects/issue_summary.tpl' => [
        '<div class="obj_issue_summary">' => 'archive cards',
        '<a class="cover"' => 'archive lazy covers',
        '</h2>' => 'archive cards (under the title)',
    ],
    'lib/pkp/templates/frontend/components/header.tpl' => [
        '<div class="pkp_site_name">' => 'image loading (logo first)',
    ],
    'lib/pkp/templates/frontend/components/footer.tpl' => [
        '<div class="pkp_structure_sidebar left" role="complementary"' => 'image loading (sidebar)',
        '</div><!-- pkp_sidebar.left -->' => 'image loading (sidebar)',
        '<div class="pkp_structure_footer_wrapper" role="contentinfo">' => 'image loading (footer)',
        '</div><!-- pkp_structure_footer_wrapper -->' => 'image loading (footer)',
        '</body>' => 'output filter (full page)',
    ],
];

if ($ojs33) {
    // The OJS 3.3 build looks for the markup of the 3.3 templates
    unset($markers['templates/frontend/objects/article_details.tpl']['{foreach from=$publication->getData(\'authors\') item=author}']);
    $markers['templates/frontend/objects/article_details.tpl']['{foreach from=$publication->getData(\'authors\') item=author}'] = 'author countries (author order)';
    $markers['templates/frontend/objects/article_details.tpl']['{$author->getLocalizedData(\'affiliation\')|escape}'] = 'author countries (affiliation text before the ROR link)';
    unset($markers['templates/frontend/objects/article_summary.tpl']['value="article-{$article->getId()}-galley-{$galley->getId()}"']);
    $markers['templates/frontend/objects/article_summary.tpl']['<ul class="galleys_links">'] = 'action rows (PDF button inside the summary)';
    unset($markers['templates/frontend/objects/galley_link.tpl']['{if $id} id="{$id}"{/if}']);
    unset($markers['templates/frontend/objects/issue_toc.tpl']['<div class="cover">']);
    $markers['templates/frontend/objects/issue_toc.tpl']['<a class="cover"'] = 'issue coverage (next to the cover)';
}

$missing = 0;
foreach ($markers as $file => $needles) {
    $path = $ojs . '/' . $file;
    if (!is_file($path)) {
        echo "MISSING FILE {$file}\n";
        $missing += count($needles);
        continue;
    }
    $content = file_get_contents($path);
    foreach ($needles as $needle => $feature) {
        if (str_contains($content, $needle)) {
            echo "ok   {$file}: {$needle}\n";
        } else {
            echo "FAIL {$file}: {$needle}  (needed by: {$feature})\n";
            $missing++;
        }
    }
}

echo $missing ? "\n{$missing} markers missing\n" : "\nAll markers found\n";
exit($missing ? 1 : 0);
