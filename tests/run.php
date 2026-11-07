<?php

/**
 * @file plugins/themes/manuscriptDaffa/tests/run.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * Tests of the theme's helpers and HTML filters against the markup of the
 * Default Theme templates of OJS 3.5. Run: php tests/run.php
 * Exits with status 1 when a test fails.
 */

require __DIR__ . '/bootstrap.php';

use APP\plugins\themes\manuscriptDaffa\classes\Countries;
use APP\plugins\themes\manuscriptDaffa\classes\IssueStats;
use APP\plugins\themes\manuscriptDaffa\ManuscriptDaffaThemePlugin as Plugin;
use APP\submission\Submission;
use APP\template\TemplateManager;

/** The plugin with access to its internals for the tests */
class TestPlugin extends Plugin
{
    public $features = null;
    public $known = null;
    public array $fail = [];
    public array $options = [];

    public function getOption($name)
    {
        return $name === 'features' ? $this->features : ($this->options[$name] ?? null);
    }

    public function getSetting($contextId, $name)
    {
        return $name === 'featuresKnown' ? $this->known : null;
    }

    public function setPage(array $page, array $submissions = []): void
    {
        $this->page = $page + ['diagnostics' => [], 'features' => [], 'description' => null, 'context' => $GLOBALS['testContext']];
        $this->submissions = $submissions;
        $this->enabledFeatures = null;
    }

    public function report_(): array
    {
        return $this->page['features'];
    }

    public function call(string $method, ...$args)
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

    protected function listData(int $id): ?array
    {
        if (in_array('list' . $id, $this->fail)) {
            throw new RuntimeException('bad article ' . $id);
        }
        return parent::listData($id);
    }
}

$GLOBALS['testContext'] = new Obj(['id' => 1, 'path' => 'jt', 'name' => 'Jurnal Test', 'description' => '<p>Deskripsi jurnal.</p>',
    'journalThumbnail' => ['uploadName' => 'thumb.png', 'altText' => 'Jurnal Test']]);

$failures = 0;
$count = 0;
function test(string $name, callable $body): void
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
function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
function same($expected, $actual, string $what): void
{
    check($expected === $actual, $what . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
}
function plugin(): TestPlugin
{
    // Errors of guarded steps go to error_log; keep the test output readable
    ini_set('error_log', '/dev/null');
    return (new ReflectionClass(TestPlugin::class))->newInstanceWithoutConstructor();
}
function tpl(string $name): object
{
    return (object) ['template_resource' => $name];
}
function article(int $id, array $publication = []): Submission
{
    return new Submission(['id' => $id, 'status' => 3, 'contextId' => 1], new Publication($publication + [
        'abstract' => "<p>Abstract {$id}</p>", 'fullTitle' => "Title {$id}", 'galleys' => [], 'doi' => null, 'pages' => null,
    ]));
}
/** An article summary as rendered by frontend/objects/article_summary.tpl */
function summary(int $id, bool $pdf = true): string
{
    return "<li>\n\t\t\t\t\t\t\n<div class=\"obj_article_summary\">\n\t<h3 class=\"title\">\n\t\t<a id=\"article-{$id}\" href=\"https://j.test/index.php/jt/article/view/{$id}\">Title {$id}</a>\n\t</h3>\n"
        . "\t<div class=\"meta\">\n\t\t<div class=\"authors\">\n\t\t\tA. Author\n\t\t</div>\n\t\t<div class=\"pages\">1-10</div>\n\t</div>\n\t<ul class=\"galleys_links\">\n"
        . ($pdf ? "<li>\n<a class=\"obj_galley_link pdf\" href=\"https://j.test/index.php/jt/article/view/{$id}/9{$id}\" id=\"article-{$id}-galley-9{$id}\" aria-labelledby=\"x\">\n\tPDF\n</a>\n</li>\n" : '')
        . "<li>\n<a class=\"obj_galley_link file\" href=\"https://j.test/h/{$id}\" id=\"article-{$id}-galley-8{$id}\">HTML</a>\n</li>\n\t</ul>\n</div>\n</li>\n";
}
/** An author as rendered by frontend/objects/article_details.tpl (with its whitespace) */
function author(string $name, ?string $affiliation, bool $ror = false): string
{
    $rorLink = $ror ? "<a href=\"https://ror.org/0094r1c12\"><?xml version=\"1.0\"?>\n<!-- Generator -->\n<svg viewBox=\"0 0 23 16\"><metadata><rdf:RDF></rdf:RDF></metadata><path d=\"M0 0\"/></svg></a>\n\t\t\t\t\t\t\t\t\t\t\t" : '';
    return "\t\t\t\t\t\t<li>\n\t\t\t\t\t\t\t<span class=\"name\">\n\t\t\t\t\t\t\t\t{$name}\n\t\t\t\t\t\t\t</span>\n"
        . ($affiliation !== null ? "\t\t\t\t\t\t\t\t\t\t\t\t\t<span class=\"affiliation\">\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t<span>{$affiliation}</span>\n\t\t\t\t\t\t\t\t\t\t\t{$rorLink}\t\t\t\t\t\t\t\t</span>\n" : '')
        . "\t\t\t\t\t\t\t\t<span class=\"userGroup\">Author</span>\n\t\t\t\t\t\t</li>\n";
}
function articlePage(string $authors): string
{
    return "<!DOCTYPE html><html><head><title>A | J</title></head><body><article class=\"obj_article_details\"><div class=\"row\"><div class=\"main_entry\">"
        . "<section class=\"item authors\"><h2 class=\"pkp_screen_reader\">Authors</h2><ul class=\"authors\">\n{$authors}</ul></section>"
        . "<section class=\"item doi\"><h2 class=\"label\">DOI:</h2></section></div></div></article></body></html>";
}
/** Text as the browser shows it: tags removed, whitespace collapsed */
function rendered(string $html): string
{
    return trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags(preg_replace('~<svg.*?</svg>|<\?xml.*?\?>|<!--.*?-->~s', '', $html)))));
}

/*
 * Helpers
 */

test('pages are counted from the Pages field', function () {
    foreach (['863-875' => 13, '12' => 1, 'e12-e20' => 9, '1-5, 9' => 6, '' => null, 'xx' => null] as $pages => $expected) {
        same($expected, IssueStats::countPages((string) $pages), "countPages('{$pages}')");
    }
    same(1029, IssueStats::startPage('1029-1038'), 'startPage');
    same(12, IssueStats::startPage('e12'), 'startPage e12');
});

test('foreign author percentage is never 0 % or 100 % by rounding', function () {
    same(1, IssueStats::percent(1, 200), '1 of 200');
    same(99, IssueStats::percent(199, 200), '199 of 200');
    same(12, IssueStats::percent(9, 75), '9 of 75');
    same(null, IssueStats::percent(0, 0), 'no known country');
});

test('country is guessed from the end of the affiliation only', function () {
    same('ID', Countries::fromAffiliation('Universitas Indonesia, Depok, Indonesia'), 'Indonesia');
    same(null, Countries::fromAffiliation('Universitas Indonesia'), 'no comma');
    same('US', Countries::fromAffiliation('MIT, Cambridge, USA'), 'USA');
    same('NL', Countries::fromAffiliation('Leiden Univ., Belanda'), 'Belanda');
});

test('articles are sorted by first page within their section, others last', function () {
    $sections = [1 => ['articles' => [article(1, ['pages' => '20-30']), article(2), article(3, ['pages' => '1-10']), article(4, ['pages' => 'e5'])]]];
    $ids = array_map(fn ($a) => $a->getId(), IssueStats::sortByPages($sections)[1]['articles']);
    same([3, 4, 1, 2], $ids, 'order');
});

/*
 * Article page
 */

test('country follows the affiliation name with the comma attached, before the ROR icon', function () {
    $p = plugin();
    $p->setPage(['template' => 'frontend/pages/article.tpl', 'authors' => [
        ['name' => 'Anis Ervina', 'country' => 'Indonesia'],
        ['name' => 'Dwi Apriyanti', 'country' => 'Indonesia'],
        ["name" => "Ann O'Neil", 'country' => 'Amerika Serikat'],
        ['name' => 'Budi', 'country' => ''],
    ]]);
    $html = articlePage(
        author('Anis Ervina', 'Universitas La Tansa Mashiro')
        . author('Dwi Apriyanti', 'Sarjana Gizi, Universitas La Tansa Mashiro, Lebak', true)
        . author('Ann O&#039;Neil', null)
        . author('Budi', 'UGM')
    );
    $out = $p->filterOutput($html, tpl('frontend/pages/article.tpl'));
    check(str_contains(rendered($out), 'Universitas La Tansa Mashiro, Indonesia Author'), 'without ROR: ' . rendered($out));
    check(str_contains(rendered($out), 'Lebak, Indonesia Author'), 'with ROR: ' . rendered($out));
    check(preg_match('~</span><span class="msd-country">, Indonesia</span>\s*<a href="https://ror.org~', $out) === 1, 'country before the ROR link');
    check(str_contains($out, '<span class="affiliation msd-country-only">Amerika Serikat</span>'), 'own line without affiliation');
    same(3, substr_count($out, 'msd-country'), 'country spans (none for an empty Country)');
    check(str_contains($p->report_()['author countries'], 'added to 3 of 4 authors (1 without Country'), 'report');
});

test('author list changed by the Publication Facts Label plugin is still found', function () {
    $p = plugin();
    $p->setPage(['template' => 'frontend/pages/article.tpl', 'authors' => [['name' => 'Anis Ervina', 'country' => 'Indonesia']]]);
    $html = str_replace('<ul class="authors">', '<ul id="author-list" class="authors">', articlePage(author('Anis Ervina', 'UIN')));
    check(str_contains($p->filterOutput($html, tpl('frontend/pages/article.tpl')), 'msd-country'), 'country added');
});

test('Dimensions badge is added above the DOI and its script is left to the front end script', function () {
    $p = plugin();
    $p->setPage(['template' => 'frontend/pages/article.tpl', 'authors' => [], 'doi' => '10.1/abc']);
    $out = $p->filterOutput(articlePage(''), tpl('frontend/pages/article.tpl'));
    check(preg_match('~data-msd-dimensions="https://badge.dimensions.ai/badge.js">.*data-doi="10.1/abc".*</section><section class="item doi">~s', $out) === 1, 'badge before the DOI');
    check(!str_contains($out, '<script'), 'no script tag in the page');
});

test('a sidebar block with a complete HTML document is not taken for the page', function () {
    $p = plugin();
    $p->setPage(['template' => 'frontend/pages/article.tpl', 'authors' => [['name' => 'Anis Ervina', 'country' => 'Indonesia']]]);
    $block = '<div class="pkp_block"><html><head></head><body><p>Widget</p></body></html></div>';
    same($block, $p->filterOutput($block, tpl('frontend/objects/block.tpl')), 'block unchanged');
    check(str_contains($p->filterOutput(articlePage(author('Anis Ervina', 'UIN')), tpl('frontend/pages/article.tpl')), 'msd-country'), 'page changed afterwards');
});

/*
 * Homepage, issue and lists
 */

function homePage(): string
{
    return "<!DOCTYPE html><html><head><title>J</title></head><body>"
        . "<div class=\"pkp_site_name\"><a class=\"is_img\"><img src=\"logo.png\" width=\"1\" /></a></div>"
        . "<div class=\"page_index_journal\"><section class=\"homepage_about\"><h2>About</h2><p>text</p></section>"
        . "<section class=\"current_issue\"><h2>Current</h2><div class=\"obj_issue_toc\"><div class=\"heading\">\n\t\t\t<div class=\"cover\">\n\t\t\t\t<img src=\"c.jpg\" alt=\"\">\n\t\t\t</div>\n<div class=\"description\">D</div></div>"
        . "<div class=\"sections\"><div class=\"section\"><h3>Articles</h3><ul class=\"cmp_article_list articles\">" . summary(1) . summary(2) . summary(3) . summary(4, false) . "</ul></div>"
        . "<div class=\"section\"><h3>Reviews</h3><ul class=\"cmp_article_list articles\">" . summary(5) . summary(6) . "</ul></div>"
        . "<div class=\"section\"><h3>Notes</h3><ul class=\"cmp_article_list articles\">" . summary(7) . "</ul></div>"
        . "</div><!-- .sections --></div><a href=\"https://j.test/index.php/jt/issue/archive\" class=\"read_more\">View All Issues</a></section></div><!-- .page -->"
        . "<div class=\"pkp_structure_sidebar left\" role=\"complementary\"><img src=\"https://s01.flagcounter.com/count/x.png\"><img src=\"idx.png\"><iframe src=\"https://youtube.com/x\"></iframe></div><!-- pkp_sidebar.left -->"
        . "<div class=\"pkp_structure_footer_wrapper\" role=\"contentinfo\"><img src=\"brand.png\" loading=\"eager\"></div><!-- pkp_structure_footer_wrapper -->"
        . "</body></html>";
}
function homeSetup(TestPlugin $p): void
{
    $subs = [];
    foreach (range(1, 7) as $i) {
        $subs[$i] = article($i, ['doi' => $i % 2 ? "10.1234/x.{$i}" : null]);
    }
    $p->setPage([
        'template' => 'frontend/pages/indexJournal.tpl',
        'description' => 'Jurnal Test. Deskripsi',
        'hiddenArticles' => array_fill_keys([6, 7], true),
        'issueUrl' => 'https://j.test/index.php/jt/issue/view/3',
        'coverage' => ['articles' => 7, 'pages' => 14, 'authors' => 9, 'countries' => ['ID' => 7, 'MY' => 2], 'known' => 9, 'foreign' => 2, 'foreignPct' => 22, 'guessed' => 1],
        'categories' => [['category' => new Obj(['title' => 'Education', 'path' => 'edu']), 'articles' => [['title' => 'Title 1', 'url' => 'u1']]]],
    ], $subs);
}

test('homepage: coverage box, folding, category cards, action rows, images, meta', function () {
    $p = plugin();
    homeSetup($p);
    $out = $p->filterOutput(homePage(), tpl('frontend/pages/indexJournal.tpl'));

    check(preg_match('~<div class="cover">.*?</div><div class="msd-coverage">~s', $out) === 1, 'coverage box right after the cover');
    check(str_contains($out, 'Issue Coverage: 9 Author(s) from 2 Country(s)'), 'coverage title');
    same(2, substr_count($out, '<li class="msd-more" hidden>'), 'hidden articles');
    same(1, substr_count($out, '<div class="section msd-more-section" hidden>'), 'section hidden with its title');
    check(str_contains($out, '</div><!-- .sections --><div class="msd-readmore-wrap"><a class="msd-readmore" href="https://j.test/index.php/jt/issue/view/3"'), 'Read more button');
    check(preg_match('~</section><section class="msd-categories">.*Education~s', $out) === 1, 'category cards under About');
    same(7, substr_count($out, 'class="msd-actions"'), 'action rows');
    same(6, substr_count($out, 'class="msd-act msd-act-pdf"'), 'Download PDF for the articles with a PDF');
    same(0, substr_count($out, 'class="obj_galley_link pdf"'), 'PDF galley buttons moved');
    same(7, substr_count($out, 'class="obj_galley_link file"'), 'other galleys kept');
    check(str_contains($out, 'href="https://doi.org/10.1234/x.1"'), 'DOI button');
    check(str_contains($out, 'scholar?q=%22Title%201%22'), 'Google Scholar exact title');
    check(str_contains($out, '<img src="logo.png" width="1" fetchpriority="high" />'), 'logo first');
    check(str_contains($out, '<img src="https://s01.flagcounter.com/count/x.png">'), 'counter not lazy');
    check(str_contains($out, '<img src="idx.png" loading="lazy">') && str_contains($out, '<iframe src="https://youtube.com/x" loading="lazy">'), 'sidebar lazy');
    check(str_contains($out, '<img src="brand.png" loading="eager">'), 'existing loading kept');
    check(str_contains($out, '<meta name="description" content="Jurnal Test. Deskripsi">'), 'meta description');
    check(str_contains($out, '<meta property="og:title" content="J">'), 'Open Graph');
});

test('one failing step or article does not stop the others', function () {
    $p = plugin();
    homeSetup($p);
    $p->fail = ['coverage', 'list2'];
    $out = $p->filterOutput(homePage(), tpl('frontend/pages/indexJournal.tpl'));
    same(0, substr_count($out, 'msd-coverage'), 'failed coverage left out');
    same(6, substr_count($out, 'class="msd-actions"'), 'rows of the other articles');
    check(str_contains($out, 'msd-readmore'), 'later steps still ran');
    $report = $p->report_();
    check(str_starts_with($report['issue coverage'], 'ERROR coverage exploded'), 'coverage error reported');
    check(str_contains($report['article action rows'], 'not added: article 2'), 'article error reported');
});

/*
 * Archives
 */

test('archive: statistic cards under each title, lazy covers after the first', function () {
    $issue = fn ($i) => "<li>\n<div class=\"obj_issue_summary\">\n\n\t\t<a class=\"cover\" href=\"u{$i}\">\n\t\t\t<img src=\"c{$i}.jpg\" alt=\"\">\n\t\t</a>\n\n\t<h2>\n\t\t<a class=\"title\" href=\"u\">Vol {$i}</a>\n\t</h2>\n\t<div class=\"description\"></div>\n</div><!-- .obj_issue_summary -->\n</li>";
    $stats = ['articles' => 5, 'pages' => 40, 'authors' => 12, 'countries' => ['ID' => 3, 'MY' => 1, 'US' => 1, 'NL' => 1, 'JP' => 1, 'SG' => 1, 'TH' => 1], 'known' => 9, 'foreign' => 6, 'foreignPct' => 67, 'guessed' => 0];
    $p = plugin();
    $p->setPage(['template' => 'frontend/pages/issueArchive.tpl', 'archive' => [$stats, $stats]]);
    $out = $p->filterOutput('<html><head></head><body><ul class="issues_archive">' . $issue(1) . $issue(2) . '</ul></body></html>', tpl('frontend/pages/issueArchive.tpl'));
    same(2, substr_count($out, '</h2><div class="msd-archive-stats">'), 'cards');
    check(str_contains($out, '67% foreign'), 'foreign share');
    check(str_contains($out, '<span class="msd-flags-more">+1</span>'), 'flags capped at 6');
    check(str_contains($out, '<img src="c1.jpg" alt="">') && str_contains($out, '<img src="c2.jpg" alt="" loading="lazy">'), 'covers');
});

/*
 * Feature switches
 */

test('feature switches: default on, saved list, later features start on', function () {
    $p = plugin();
    $p->setPage(['template' => 'x']);
    $off = function () use ($p) {
        $p->setPage(['template' => 'x']);
        return array_values(array_filter(Plugin::FEATURES, fn ($f) => !$p->featureEnabled($f)));
    };
    $p->features = null;
    same([], $off(), 'never saved');
    $p->features = array_values(array_diff(Plugin::FEATURES, ['dimensions', 'homeFold']));
    $p->known = Plugin::FEATURES;
    same(['dimensions', 'homeFold'], $off(), 'two switched off');
    $p->features = array_values(array_diff(Plugin::FEATURES, ['openGraph', 'dimensions']));
    $p->known = array_values(array_diff(Plugin::FEATURES, ['openGraph']));
    same(['dimensions'], $off(), 'feature unknown when the list was saved starts on');
});

test('switched-off steps leave the page alone and are reported', function () {
    $p = plugin();
    $p->features = array_values(array_diff(Plugin::FEATURES, ['actionRows', 'metaDescription', 'openGraph']));
    $p->known = Plugin::FEATURES;
    homeSetup($p);
    $p->features = array_values(array_diff(Plugin::FEATURES, ['actionRows', 'metaDescription', 'openGraph']));
    $out = $p->filterOutput(homePage(), tpl('frontend/pages/indexJournal.tpl'));
    same(0, substr_count($out, 'msd-actions'), 'no action rows');
    same(0, substr_count($out, 'name="description"'), 'no meta description');
    same(0, substr_count($out, 'og:'), 'no Open Graph');
    same('disabled in the theme settings', $p->report_()['article action rows'], 'report');
});

/*
 * Open Graph
 */

test('Open Graph: article cover, fallbacks and existing tags', function () {
    $p = plugin();
    $head = '<html><head><title>Page | J</title></head><body></body></html>';

    $p->setPage(['template' => 'frontend/pages/article.tpl', 'description' => 'Abstrak.']);
    $p->call('prepareOpenGraph', new TemplateManager([
        'publication' => new Publication(['fullTitle' => 'Judul <i>Artikel</i>', 'cover' => 'https://j.test/public/journals/1/cover.jpg', 'coverImage' => ['altText' => ''], 'datePublished' => '2026-05-30']),
        'article' => article(24),
    ]), 'frontend/pages/article.tpl');
    $out = $p->filterOutput($head, tpl('frontend/pages/article.tpl'));
    foreach ([
        '<meta property="og:type" content="article">',
        '<meta property="og:title" content="Judul Artikel">',
        '<meta property="og:url" content="https://j.test/index.php/jt/article/view/24">',
        '<meta property="og:image" content="https://j.test/public/journals/1/cover.jpg">',
        '<meta property="article:published_time" content="2026-05-30">',
        '<meta name="twitter:card" content="summary_large_image">',
    ] as $tag) {
        check(str_contains($out, $tag), 'missing ' . $tag);
    }

    $p->setPage(['template' => 'frontend/pages/about.tpl', 'description' => 'D']);
    $p->call('prepareOpenGraph', new TemplateManager([]), 'frontend/pages/about.tpl');
    $out = $p->filterOutput($head, tpl('frontend/pages/about.tpl'));
    check(str_contains($out, '<meta property="og:title" content="Page | J">'), 'title from <title>');
    check(str_contains($out, '<meta property="og:image" content="https://j.test/public/journals/1/thumb.png">'), 'journal thumbnail fallback');

    $p->setPage(['template' => 'frontend/pages/about.tpl', 'description' => 'D']);
    $out = $p->filterOutput(str_replace('</head>', '<meta property="og:title" content="plugin"></head>', $head), tpl('frontend/pages/about.tpl'));
    same(1, substr_count($out, 'og:title'), 'existing tags kept, none added');
});

/*
 * Assets
 */

test('theme assets carry the theme version', function () {
    $release = (string) simplexml_load_file(dirname(__DIR__) . '/version.xml')->release;
    check(preg_match('/^\d+\.\d+\.\d+\.\d+$/', $release) === 1, 'version.xml release has four numbers: ' . $release);
    same('styles/manuscriptDaffa.css?v=' . $release, plugin()->call('versioned', 'styles/manuscriptDaffa.css'), 'versioned path');
});

test('locale files define every key used in the code', function () {
    $code = file_get_contents(dirname(__DIR__) . '/ManuscriptDaffaThemePlugin.php');
    preg_match_all("~'(plugins\\.themes\\.manuscriptDaffa\\.[a-zA-Z0-9.]+)'~", $code, $m);
    $keys = array_unique($m[1]);
    foreach (Plugin::FEATURES as $feature) {
        $keys[] = 'plugins.themes.manuscriptDaffa.option.features.' . $feature;
    }
    foreach (['en', 'id'] as $locale) {
        $po = file_get_contents(dirname(__DIR__) . "/locale/{$locale}/locale.po");
        foreach ($keys as $key) {
            if (str_ends_with($key, '.')) {
                continue; // prefix of a key built in the code
            }
            check(str_contains($po, 'msgid "' . $key . '"'), "{$locale}: missing {$key}");
        }
    }
});

test('the output filter is registered under its own name', function () {
    // Unnamed closures share the name "closure" in Smarty and replace each
    // other (see smarty-filter.php), e.g. with the Publication Facts Label plugin.
    $code = file_get_contents(dirname(__DIR__) . '/ManuscriptDaffaThemePlugin.php');
    check(str_contains($code, "registerFilter('output', \$this->filterOutput(...), 'manuscriptDaffa')"), 'named registerFilter call not found');
});

test('header logo height and position give the expected desktop CSS', function () {
    $p = plugin();
    $css = function ($height, $position) use ($p) {
        $p->options = ['headerLogoHeight' => $height, 'headerLogoPosition' => $position];
        return $p->call('headerLogoCss');
    };
    $media = '@media (min-width:992px){';
    $row = '.has_site_logo .pkp_head_wrapper{padding-top:3.2rem}';
    same('', $css(null, null), 'nothing saved');
    same('', $css('80', 'left'), 'defaults');
    same('', $css('bad', 'bad'), 'unknown values fall back to the defaults');
    same($media . '.pkp_site_name .is_img img{max-height:120px}}', $css('120', 'left'), '120 px left');
    same($media . $row . '.pkp_site_name{width:100%;text-align:center}}', $css('80', 'center'), '80 px centre');
    same($media . '.pkp_site_name .is_img img{max-height:150px}' . $row . '.pkp_site_name{width:100%;text-align:right}}', $css('150', 'right'), '150 px right');
    check(strpos($css('full', 'right'), 'text-align') === false, 'full width ignores the position');
    same($css('full', 'left'), $css('full', 'center'), 'full width is the same for every position');
});

test('sidebar menu: link lists in the sidebar get icons, the current page is marked, the rest is left alone', function () {
    $p = plugin();
    $sidebar = '<div class="pkp_structure_sidebar left" role="complementary"><div class="pkp_block block_custom" id="customblock-menu"><div class="content">'
        . '<p><img src="sinta.png" alt="SINTA"></p><p><a href="https://j.test/index.php/jt/index">Home</a></p><p>&nbsp;</p>'
        . '<p><a href="https://j.test/index.php/jt/about/">About the Journal</a></p><p><strong><a href="/index.php/jt/fees">Author Fees</a></strong></p>'
        . '<p><a href="https://j.test/index.php/jt/indexing">Abstracting &amp; Indexing</a></p></div></div>'
        . '<div class="pkp_block block_custom"><div class="content"><p><a href="https://scholar.google.com"><img src="gs.png"></a></p>'
        . '<p><a href="https://doaj.org"><img src="doaj.png"></a></p><p><a href="https://garuda.kemdikbud.go.id"><img src="garuda.png"></a></p></div></div>'
        . '<div class="pkp_block block_information"><div class="content"><ul><li><a href="/r">For Readers</a></li><li><a href="/a">For Authors</a></li><li><a href="/l">For Librarians</a></li></ul></div></div>'
        . '</div><!-- pkp_sidebar.left -->';
    $main = '<div class="pkp_structure_main"><ul><li><a href="/x">Home</a></li><li><a href="/y">Contact</a></li></ul></div>';
    $page = '<html><body>' . $main . $sidebar . '</body></html>';

    $p->options = ['sidebarMenu' => 'soft'];
    $out = $p->call('filterSidebarMenu', $page);
    check(strpos($out, $main) !== false, 'lists outside the sidebar are not changed');
    same(2, substr_count($out, '<ul class="msd_menu msd_menu_soft"'), 'two menus (custom block, Information block)');
    check(strpos($out, '<img src="sinta.png" alt="SINTA">') !== false && strpos($out, '<p><a href="https://doaj.org"><img src="doaj.png"></a></p>') !== false, 'images and logo links kept');
    check((bool) preg_match('~<a href="https://j.test/index.php/jt/about/" aria-current="page" class="msd_menu_link msd_i_info msd_menu_current">~', $out), 'current page marked (trailing slash ignored)');
    same(1, substr_count($out, 'aria-current'), 'only one current link');
    foreach (['home', 'fee', 'indexing', 'reader', 'guide', 'library'] as $icon) {
        check(strpos($out, 'msd_i_' . $icon . '"') !== false || strpos($out, 'msd_i_' . $icon . ' ') !== false, 'icon ' . $icon);
    }
    check(strpos($out, '<span class="msd_menu_text">Abstracting &amp; Indexing</span>') !== false, 'link text kept');
    check(strpos($out, '<p>&nbsp;</p>') === false, 'empty paragraph inside the run removed');

    $p->options = ['sidebarMenu' => 'solid'];
    check(strpos($p->call('filterSidebarMenu', $page), 'msd_menu msd_menu_solid') !== false, 'solid style');
    $p->options = ['sidebarMenu' => 'off'];
    same($page, $p->call('filterSidebarMenu', $page), 'off leaves the page alone');
});

test('sidebar menu: the journal home link is current on .../index too', function () {
    same(\APP\plugins\themes\manuscriptDaffa\classes\SideMenu::normaliseUrl('https://aulad.org/index.php/aulad'), \APP\plugins\themes\manuscriptDaffa\classes\SideMenu::normaliseUrl('https://aulad.org/index.php/aulad/index'), 'journal home');
    same(\APP\plugins\themes\manuscriptDaffa\classes\SideMenu::normaliseUrl('https://aulad.org/index.php/aulad/'), \APP\plugins\themes\manuscriptDaffa\classes\SideMenu::normaliseUrl('https://aulad.org/index.php/aulad/index/index'), 'journal home, longer form');
    same(\APP\plugins\themes\manuscriptDaffa\classes\SideMenu::normaliseUrl('https://aulad.org/index.php/aulad/about'), \APP\plugins\themes\manuscriptDaffa\classes\SideMenu::normaliseUrl('https://aulad.org/index.php/aulad/about/index'), 'about');
    check(\APP\plugins\themes\manuscriptDaffa\classes\SideMenu::normaliseUrl('https://aulad.org/index.php/aulad/about/editorialTeam') !== \APP\plugins\themes\manuscriptDaffa\classes\SideMenu::normaliseUrl('https://aulad.org/index.php/aulad/about'), 'other pages differ');
    check(\APP\plugins\themes\manuscriptDaffa\classes\SideMenu::normaliseUrl('https://aulad.org/index.php/aulad/about/submissions#authorGuidelines') === \APP\plugins\themes\manuscriptDaffa\classes\SideMenu::normaliseUrl('https://aulad.org/index.php/aulad/about/submissions'), 'anchor ignored');
});

test('sidebar menu: links separated by line breaks and icons from the link text', function () {
    $p = plugin();
    $p->options = [];
    $page = '<div class="pkp_structure_sidebar left" role="complementary"><div class="content"><p><a href="/a">Beranda</a><br><a href="/b">Tim Redaksi</a><br />'
        . "\n" . '<a href="/c">Hubungi Kami</a></p></div></div><!-- pkp_sidebar.left -->';
    $out = $p->call('filterSidebarMenu', $page);
    check(strpos($out, '<div class="content"><ul class="msd_menu msd_menu_soft"><li><a href="/a" class="msd_menu_link msd_i_home">') !== false, 'paragraph replaced by the menu: ' . $out);
    check(strpos($out, '<br') === false && strpos($out, '<p>') === false, 'no breaks or paragraph left');
    $expected = ['Home' => 'home', 'Focus and Scope' => 'focus', 'Editorial Team' => 'team', 'Peer Reviewers' => 'reviewers',
        'Peer Review Process' => 'process', 'Publication Ethics' => 'ethics', 'Plagiarism Policy' => 'plagiarism', 'Author Guidelines' => 'guide',
        'Author Fees' => 'fee', 'Article Processing Charge' => 'fee', 'Open Access Policy' => 'openAccess', 'Generative AI Policy' => 'ai',
        'Copyright Notice' => 'copyright', 'Journal License' => 'license', 'Abstracting & Indexing' => 'indexing', 'Article Template' => 'template',
        'Contact Us' => 'contact', 'Kebijakan Akses Terbuka' => 'openAccess', 'Mitra Bestari' => 'reviewers', 'Pedoman Penulis' => 'guide',
        'Something else' => 'page'];
    foreach ($expected as $text => $icon) {
        same($icon, \APP\plugins\themes\manuscriptDaffa\classes\SideMenu::iconFor($text), $text);
    }
});

test('the OJS 3.3 copy of the sidebar menu class is the same', function () {
    $body = function ($file) {
        $code = file_get_contents(dirname(__DIR__) . '/' . $file);
        return substr($code, strpos($code, 'class ', strpos($code, '*/')));
    };
    same($body('classes/SideMenu.php'), str_replace('class MsdSideMenu', 'class SideMenu', $body('ojs33/classes/MsdSideMenu.inc.php')), 'class body');
});

echo "\n" . ($count - $failures) . " of {$count} tests passed\n";
exit($failures ? 1 : 0);
