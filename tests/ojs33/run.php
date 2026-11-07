<?php

/**
 * @file plugins/themes/manuscriptDaffa/tests/ojs33/run.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * Tests of the OJS 3.3 build against the markup of the OJS 3.3 Default
 * Theme templates. PHP 7.3 compatible. Run: php tests/ojs33/run.php
 */

require __DIR__ . '/bootstrap.php';

/** The plugin with access to its internals for the tests */
class TestPlugin extends ManuscriptDaffaThemePlugin
{
    public $features = null;
    public $known = null;
    public $fail = [];

    public function getOption($name)
    {
        return $name === 'features' ? $this->features : null;
    }

    public function getSetting($contextId, $name)
    {
        return $name === 'featuresKnown' ? $this->known : null;
    }

    public function setPage(array $page, array $submissions = [])
    {
        $this->page = $page + ['diagnostics' => [], 'features' => [], 'description' => null, 'context' => $GLOBALS['testContext']];
        $this->submissions = $submissions;
        $this->enabledFeatures = null;
    }

    public function reported()
    {
        return $this->page['features'];
    }

    public function call($method, ...$args)
    {
        return $this->$method(...$args);
    }

    protected function filterCoverage(string $html): string
    {
        if (in_array('coverage', $this->fail)) {
            throw new RuntimeException('coverage exploded');
        }
        return parent::filterCoverage($html);
    }
}

$GLOBALS['testContext'] = new Obj(['id' => 1, 'path' => 'jt', 'name' => 'Jurnal Test', 'description' => '<p>Deskripsi.</p>',
    'journalThumbnail' => ['uploadName' => 'thumb.png', 'altText' => 'Jurnal Test']]);
ini_set('error_log', '/dev/null');

$failures = 0;
$count = 0;
function test($name, callable $body)
{
    global $failures, $count;
    $count++;
    try {
        $body();
        echo "ok   {$name}\n";
    } catch (Throwable $e) {
        $failures++;
        echo "FAIL {$name}\n     " . $e->getMessage() . "\n";
    }
}
function check($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
function same($expected, $actual, $what)
{
    check($expected === $actual, $what . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
}
function plugin()
{
    return (new ReflectionClass('TestPlugin'))->newInstanceWithoutConstructor();
}
function tpl($name)
{
    return (object) ['template_resource' => $name];
}
function article($id, array $publication = [])
{
    return new Submission(['id' => $id, 'status' => 3, 'contextId' => 1], new Publication($publication + [
        'abstract' => "<p>Abstract {$id}</p>", 'fullTitle' => "Title {$id}", 'galleys' => [], 'pages' => null,
    ]));
}
/** An article summary as rendered by the OJS 3.3 article_summary.tpl (galley links without id) */
function summary($id, $pdf = true)
{
    return "<li>\n<div class=\"obj_article_summary\">\n\t<h3 class=\"title\">\n\t\t<a id=\"article-{$id}\" href=\"https://j.test/index.php/jt/article/view/{$id}\">Title {$id}</a>\n\t</h3>\n"
        . "\t<div class=\"meta\">\n\t\t<div class=\"authors\">\n\t\t\tA. Author\n\t\t</div>\n\t\t<div class=\"pages\">1-10</div>\n\t</div>\n\t<ul class=\"galleys_links\">\n"
        . ($pdf ? "\t\t<li>\n<a class=\"obj_galley_link pdf\" href=\"https://j.test/index.php/jt/article/view/{$id}/9{$id}\" aria-labelledby=article-{$id}>\n\tPDF\n</a>\n\t\t</li>\n" : '')
        . "\t\t<li>\n<a class=\"obj_galley_link file\" href=\"https://j.test/h/{$id}\" aria-labelledby=article-{$id}>HTML</a>\n\t\t</li>\n\t</ul>\n</div>\n</li>\n";
}
/** An author as rendered by the OJS 3.3 article_details.tpl */
function author($name, $affiliation, $ror = false)
{
    return "\t\t\t\t\t\t<li>\n\t\t\t\t\t\t\t<span class=\"name\">\n\t\t\t\t\t\t\t\t{$name}\n\t\t\t\t\t\t\t</span>\n"
        . ($affiliation !== null ? "\t\t\t\t\t\t\t\t<span class=\"affiliation\">\n\t\t\t\t\t\t\t\t\t{$affiliation}\n"
            . ($ror ? "\t\t\t\t\t\t\t\t\t\t<a href=\"https://ror.org/0094r1c12\"><svg viewBox=\"0 0 23 16\"><path d=\"M0 0\"/></svg></a>\n" : '')
            . "\t\t\t\t\t\t\t\t</span>\n" : '')
        . "\t\t\t\t\t\t</li>\n";
}
function articlePage($authors)
{
    return "<!DOCTYPE html><html><head><title>A | J</title></head><body><article class=\"obj_article_details\"><div class=\"main_entry\">"
        . "<section class=\"item authors\"><h2 class=\"pkp_screen_reader\">Authors</h2><ul class=\"authors\">\n{$authors}</ul></section>"
        . "<section class=\"item doi\"><h2 class=\"label\">DOI:</h2></section></div></article></body></html>";
}
function rendered($html)
{
    return trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags(preg_replace('~<svg.*?</svg>~s', '', $html)))));
}
function homePage()
{
    return "<!DOCTYPE html><html><head><title>J</title></head><body>"
        . "<div class=\"pkp_site_name\"><a class=\"is_img\"><img src=\"logo.png\" /></a></div>"
        . "<div class=\"page_index_journal\"><section class=\"homepage_about\"><h2>About</h2><p>text</p></section>"
        . "<section class=\"current_issue\"><h2>Current</h2><div class=\"obj_issue_toc\"><div class=\"heading\">\n\t\t\t<a class=\"cover\" href=\"https://j.test/index.php/jt/issue/view/3\">\n\t\t\t\t<img src=\"c.jpg\" alt=\"\">\n\t\t\t</a>\n<div class=\"description\">D</div></div>"
        . "<div class=\"sections\"><div class=\"section\"><h3>Articles</h3><ul class=\"cmp_article_list articles\">" . summary(1) . summary(2) . summary(3) . summary(4, false) . summary(5) . summary(6, false) . "</ul></div>"
        . "</div><!-- .sections --></div><a href=\"https://j.test/index.php/jt/issue/archive\" class=\"read_more\">View All Issues</a></section></div><!-- .page -->"
        . "<div class=\"pkp_structure_sidebar left\" role=\"complementary\" aria-label=\"Sidebar\"><img src=\"https://s01.flagcounter.com/x.png\"><img src=\"idx.png\"></div><!-- pkp_sidebar.left -->"
        . "<div class=\"pkp_structure_footer_wrapper\" role=\"contentinfo\"><ul><li>\n<a class=\"obj_galley_link pdf\" href=\"https://j.test/footer.pdf\">PDF</a>\n</li></ul></div><!-- pkp_structure_footer_wrapper -->"
        . "</body></html>";
}
function homeSetup(TestPlugin $p)
{
    $subs = [];
    foreach (range(1, 6) as $i) {
        $subs[$i] = article($i, ['pub-id::doi' => $i % 2 ? "10.1234/x.{$i}" : null]);
    }
    $p->setPage([
        'template' => 'frontend/pages/indexJournal.tpl',
        'description' => 'Jurnal Test. Deskripsi',
        'hiddenArticles' => [6 => true],
        'issueUrl' => 'https://j.test/index.php/jt/issue/view/3',
        'coverage' => ['articles' => 6, 'pages' => 12, 'authors' => 9, 'countries' => ['ID' => 7, 'MY' => 2], 'known' => 9, 'foreign' => 2, 'foreignPct' => 22, 'guessed' => 0],
    ], $subs);
}

test('country follows the affiliation text, before the ROR link, comma attached', function () {
    $p = plugin();
    $p->setPage(['template' => 'frontend/pages/article.tpl', 'authors' => [
        ['name' => 'Anis Ervina', 'country' => 'Indonesia'],
        ['name' => 'Dwi Apriyanti', 'country' => 'Indonesia'],
        ['name' => 'Budi', 'country' => 'Malaysia'],
    ]]);
    $out = $p->filterOutput(articlePage(
        author('Anis Ervina', 'Universitas La Tansa Mashiro')
        . author('Dwi Apriyanti', 'Universitas La Tansa Mashiro, Lebak', true)
        . author('Budi', null)
    ), tpl('frontend/pages/article.tpl'));
    check(strpos(rendered($out), 'Anis Ervina Universitas La Tansa Mashiro, Indonesia Dwi') !== false, 'without ROR: ' . rendered($out));
    check(strpos(rendered($out), 'Lebak, Indonesia Budi') !== false, 'with ROR: ' . rendered($out));
    check(preg_match('~Lebak<span class="msd-country">, Indonesia</span>\s*<a href="https://ror.org~', $out) === 1, 'country before the ROR link');
    check(strpos($out, '<span class="affiliation msd-country-only">Malaysia</span>') !== false, 'own line without affiliation');
});

test('DOI from the DOI plugin data gives the Dimensions badge and the DOI button', function () {
    $p = plugin();
    same('10.1/abc', $p->call('doiOf', new Publication(['pub-id::doi' => '10.1/abc'])), 'doiOf');
    same(null, $p->call('doiOf', new Publication([])), 'no DOI');
    $p->setPage(['template' => 'frontend/pages/article.tpl', 'authors' => [], 'doi' => '10.1/abc']);
    $out = $p->filterOutput(articlePage(''), tpl('frontend/pages/article.tpl'));
    check(preg_match('~data-doi="10.1/abc".*</section><section class="item doi">~s', $out) === 1, 'badge above the DOI');
});

test('homepage: coverage next to the cover link, fold, PDF moved without galley ids', function () {
    $p = plugin();
    homeSetup($p);
    $out = $p->filterOutput(homePage(), tpl('frontend/pages/indexJournal.tpl'));
    check(preg_match('~<a class="cover"[^>]*>.*?</a><div class="msd-coverage">~s', $out) === 1, 'coverage right after the cover link');
    same(6, substr_count($out, 'class="msd-actions"'), 'action rows');
    same(4, substr_count($out, 'class="msd-act msd-act-pdf"'), 'Download PDF for the four articles with a PDF');
});

test('PDF buttons: each article gets its own, page links outside the list are left alone', function () {
    $p = plugin();
    homeSetup($p);
    $out = $p->filterOutput(homePage(), tpl('frontend/pages/indexJournal.tpl'));
    foreach ([1, 2, 3, 5] as $id) {
        check(strpos($out, "class=\"msd-act msd-act-pdf\" href=\"https://j.test/index.php/jt/article/view/{$id}/9{$id}\"") !== false, "article {$id} has its own PDF link");
    }
    same(0, substr_count($out, '<a class="obj_galley_link pdf" href="https://j.test/index.php/jt/article'), 'article PDF galley buttons moved');
    check(strpos($out, '<a class="obj_galley_link pdf" href="https://j.test/footer.pdf">') !== false, 'PDF link outside the article list kept (last article has no PDF)');
    same(6, substr_count($out, 'class="obj_galley_link file"'), 'other galleys kept');
    same(1, substr_count($out, '<li class="msd-more" hidden>'), 'sixth article folded');
    check(strpos($out, 'href="https://doi.org/10.1234/x.1"') !== false, 'DOI button');
    check(strpos($out, '<img src="logo.png" fetchpriority="high" />') !== false, 'logo first');
    check(strpos($out, '<img src="idx.png" loading="lazy">') !== false && strpos($out, 'flagcounter.com/x.png">') !== false, 'sidebar lazy, counter not');
});

test('one failing step does not stop the others', function () {
    $p = plugin();
    homeSetup($p);
    $p->fail = ['coverage'];
    $out = $p->filterOutput(homePage(), tpl('frontend/pages/indexJournal.tpl'));
    same(0, substr_count($out, 'msd-coverage'), 'failed coverage left out');
    same(6, substr_count($out, 'class="msd-actions"'), 'later steps ran');
    check(strpos($p->reported()['issue coverage'], 'ERROR coverage exploded') === 0, 'error reported');
});

test('feature switches, Open Graph and sidebar blocks', function () {
    $p = plugin();
    $p->features = array_values(array_diff(ManuscriptDaffaThemePlugin::FEATURES, ['actionRows']));
    $p->known = ManuscriptDaffaThemePlugin::FEATURES;
    homeSetup($p);
    $p->features = array_values(array_diff(ManuscriptDaffaThemePlugin::FEATURES, ['actionRows']));
    $block = '<div><html><body>widget</body></html></div>';
    same($block, $p->filterOutput($block, tpl('frontend/objects/block.tpl')), 'sidebar block unchanged');
    $p->call('prepareOpenGraph', new TemplateManager([]), 'frontend/pages/indexJournal.tpl');
    $out = $p->filterOutput(homePage(), tpl('frontend/pages/indexJournal.tpl'));
    same(0, substr_count($out, 'msd-actions'), 'switched-off action rows');
    check(strpos($out, '<meta property="og:title" content="Jurnal Test">') !== false, 'Open Graph title');
    check(strpos($out, '<meta property="og:url" content="https://j.test/index.php/jt/index/index">') !== false, 'Open Graph URL');
    check(strpos($out, '<meta property="og:image" content="https://j.test/public/journals/1/thumb.png">') !== false, 'thumbnail fallback image');
});

test('statistics helpers', function () {
    same(13, MsdIssueStats::countPages('863-875'), 'pages');
    same(12, MsdIssueStats::percent(9, 75), 'percent');
    same('ID', MsdCountries::fromAffiliation('Universitas Indonesia, Depok, Indonesia'), 'country from affiliation');
    same(['Indonesia'], array_values(array_intersect(['Indonesia'], MsdCountries::all())), 'country list');
    $author = new Obj(['country' => '', 'affiliation' => 'MIT, Cambridge, USA']);
    same(['US', true], MsdIssueStats::countryOf($author), 'country guessed from the 3.3 affiliation string');
});

test('file cache keeps values and recomputes after a put', function () {
    $key = 'test-' . getmypid();
    same(1, MsdCache::remember($key, 60, function () {
        return 1;
    }), 'first');
    same(1, MsdCache::remember($key, 60, function () {
        return 2;
    }), 'cached');
    MsdCache::put($key, 3);
    same(3, MsdCache::remember($key, 60, function () {
        return 4;
    }), 'after put');
});

test('same version as the OJS 3.4/3.5 build, versioned assets, locale keys', function () {
    $root = dirname(__DIR__, 2);
    $release = (string) simplexml_load_file($root . '/ojs33/version.xml')->release;
    same((string) simplexml_load_file($root . '/version.xml')->release, $release, 'version');
    same('styles/manuscriptDaffa.css?v=' . $release, plugin()->call('versioned', 'styles/manuscriptDaffa.css'), 'versioned path');
    $code = file_get_contents($root . '/ojs33/ManuscriptDaffaThemePlugin.inc.php');
    preg_match_all("~'(plugins\\.themes\\.manuscriptDaffa\\.[a-zA-Z0-9.]+)'~", $code, $m);
    $keys = array_unique($m[1]);
    foreach (ManuscriptDaffaThemePlugin::FEATURES as $feature) {
        $keys[] = 'plugins.themes.manuscriptDaffa.option.features.' . $feature;
    }
    foreach (['en_US', 'id_ID'] as $locale) {
        $po = file_get_contents($root . "/ojs33/locale/{$locale}/locale.po");
        foreach ($keys as $key) {
            if (substr($key, -1) !== '.') {
                check(strpos($po, 'msgid "' . $key . '"') !== false, "{$locale}: missing {$key}");
            }
        }
    }
});

test('the output filter is registered under its own name', function () {
    $code = file_get_contents(dirname(__DIR__, 2) . '/ojs33/ManuscriptDaffaThemePlugin.inc.php');
    check(strpos($code, "registerFilter('output', [\$this, 'filterOutput'], 'manuscriptDaffa')") !== false, 'named registerFilter call not found');
    check(strpos($code, "HookRegistry::register('TemplateManager::display', [\$this, 'handleDisplay'], HOOK_SEQUENCE_CORE)") !== false, 'display hook');
});

echo "\n" . ($count - $failures) . " of {$count} tests passed\n";
exit($failures ? 1 : 0);
