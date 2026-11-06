<?php

/**
 * @file plugins/themes/manuscriptDaffa/ManuscriptDaffaThemePlugin.php
 *
 * Copyright (c) 2014-2020 Simon Fraser University
 * Copyright (c) 2003-2020 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class ManuscriptDaffaThemePlugin
 *
 * @brief Manuscript Daffa: the Manuscript child theme of the Default Theme
 *  with extra features for journals. No core template is copied: the theme
 *  prepares data when a page is displayed (handleDisplay) and changes the
 *  rendered HTML of the Default Theme templates with a Smarty output filter.
 */

namespace APP\plugins\themes\manuscriptDaffa;

use APP\core\Application;
use APP\facades\Repo;
use APP\plugins\themes\manuscriptDaffa\classes\Countries;
use APP\plugins\themes\manuscriptDaffa\classes\FileSizes;
use APP\plugins\themes\manuscriptDaffa\classes\Html;
use APP\plugins\themes\manuscriptDaffa\classes\IssueStats;
use APP\submission\Submission;
use APP\template\TemplateManager;
use PKP\core\PKPString;
use PKP\plugins\Hook;
use PKP\plugins\PluginRegistry;
use PKP\plugins\ThemePlugin;
use PKP\security\Role;
use Throwable;

class ManuscriptDaffaThemePlugin extends ThemePlugin
{
    /** Articles of the current issue shown on the homepage before "Read more" */
    public const HOME_ARTICLE_LIMIT = 5;

    /** Articles listed in a category card on the homepage */
    public const CATEGORY_ARTICLE_LIMIT = 20;

    /** Flags shown on an Archives card before "+N" */
    public const ARCHIVE_FLAG_LIMIT = 6;

    /** Files from this size (bytes) are marked in the back end file lists */
    public const LARGE_FILE_BYTES = 5 * 1024 * 1024;

    /** Request parameter of the back end file size lookup */
    public const FILE_SIZE_PARAM = 'manuscriptDaffaFileSizes';

    /** Image hosts of visitor counters that must not be lazy-loaded */
    public const COUNTER_PATTERN = '~flagcounter|statcounter|histats|clustrmaps|whos\.amung|revolvermaps|hitwebcounter|freecounter|free-counter|counter\.|/counter|supercounters|amazingcounters|website-hit-counters|visitor-?map|livetraffic|statsy|hitcounter~i';

    /** @var array Data collected in handleDisplay for the output filter */
    protected array $page = [];

    /** @var array<int,Submission> Submissions already loaded by the page */
    protected array $submissions = [];

    /**
     * @copydoc ThemePlugin::init
     */
    public function init()
    {
        // Initialize the parent theme
        $this->setParent('defaultthemeplugin');

        // Manuscript styles
        $this->modifyStyle('stylesheet', ['addLess' => ['styles/index.less']]);

        // Remove the typography options of the parent theme.
        $this->removeOption('typography');
        $this->removeOption('useHomepageImageAsHeader');

        // Accent colour
        $this->addOption('accentColour', 'FieldColor', [
            'label' => __('plugins.themes.manuscriptDaffa.option.accentColour.label'),
            'description' => __('plugins.themes.default.option.colour.description'),
            'default' => '#F7BC4A',
        ]);

        // Home country of the journal (foreign author percentage)
        $this->addHomeCountryOption();

        // Dequeue any fonts loaded by parent theme
        if (method_exists($this, 'removeStyle')) {
            for ($i = 0; $i < 11; $i++) {
                $this->removeStyle('font');
            }
        }

        // Start with a fresh array of additionalLessVariables so that we can
        // ignore those added by the parent theme.
        $additionalLessVariables = [];

        $baseColour = $this->safeColour($this->getOption('baseColour'), '#1E6292');
        if ($baseColour !== '#1E6292') {
            $additionalLessVariables[] = '@bg-base:' . $baseColour . ';';
            if (!$this->isColourDark($baseColour)) {
                $additionalLessVariables[] = '@text-bg-base:rgba(0,0,0,0.84);';
            }
        }

        $accentColour = $this->safeColour($this->getOption('accentColour'), '#F7BC4A');
        if ($accentColour !== '#F7BC4A') {
            $additionalLessVariables[] = '@accent:' . $accentColour . ';';
        }

        $this->modifyStyle('stylesheet', ['addLessVariables' => join('', $additionalLessVariables)]);

        // Feature styles follow the colours chosen in OJS
        $this->addStyle('manuscriptDaffa', 'styles/manuscriptDaffa.css');
        $this->addStyle(
            'manuscriptDaffaColours',
            ':root{--msd-primary:' . $baseColour . ';--msd-accent:' . $accentColour
                . ';--msd-on-primary:' . ($this->isColourDark($baseColour) ? '#fff' : 'rgba(0,0,0,.84)') . ';}',
            ['inline' => true]
        );

        // Front end behaviour (abstract toggle, "Read more")
        $this->addScript('manuscriptDaffa', 'js/manuscriptDaffa.js');

        // Back end helpers, only for logged-in users
        $this->addAdminScript();

        Hook::add('TemplateManager::display', $this->handleDisplay(...));
    }

    /**
     * @copydoc ThemePlugin::saveOption
     */
    public function saveOption($name, $value, $contextId = null)
    {
        // pkp/pkp-lib#11974
        if (in_array($name, ['baseColour', 'accentColour']) && !preg_match('/^#[0-9a-fA-F]{1,6}$/', (string) $value)) {
            $value = null;
        }
        if ($name === 'homeCountry') {
            $value = strtoupper(trim((string) $value));
            if (!preg_match('/^[A-Z]{2}$/', $value)) {
                $value = '';
            }
        }

        parent::saveOption($name, $value, $contextId);
    }

    /**
     * @copydoc Plugin::getDisplayName
     */
    public function getDisplayName()
    {
        return __('plugins.themes.manuscriptDaffa.name');
    }

    /**
     * @copydoc Plugin::getDescription
     */
    public function getDescription()
    {
        return __('plugins.themes.manuscriptDaffa.description');
    }

    /**
     * Home country of the journal (two-letter code) or ''.
     */
    public function getHomeCountry(): string
    {
        $value = strtoupper(trim((string) $this->getOption('homeCountry')));
        return preg_match('/^[A-Z]{2}$/', $value) ? $value : '';
    }

    /*
     * Setup
     */

    protected function safeColour($colour, string $default): string
    {
        return is_string($colour) && preg_match('/^#[0-9a-fA-F]{1,6}$/', $colour) ? $colour : $default;
    }

    /**
     * A select list with the full country names, like the author form.
     * Falls back to a text field for the two-letter code when the country
     * list can not be loaded.
     */
    protected function addHomeCountryOption(): void
    {
        $options = [];
        try {
            foreach (Countries::all() as $code => $name) {
                $options[] = ['value' => $code, 'label' => $name];
            }
        } catch (Throwable $e) {
            $options = [];
        }

        if (count($options)) {
            array_unshift($options, ['value' => '', 'label' => __('plugins.themes.manuscriptDaffa.option.homeCountry.none')]);
            $this->addOption('homeCountry', 'FieldSelect', [
                'label' => __('plugins.themes.manuscriptDaffa.option.homeCountry.label'),
                'description' => __('plugins.themes.manuscriptDaffa.option.homeCountry.description'),
                'options' => $options,
                'default' => '',
            ]);
        } else {
            $this->addOption('homeCountry', 'FieldText', [
                'label' => __('plugins.themes.manuscriptDaffa.option.homeCountry.label'),
                'description' => __('plugins.themes.manuscriptDaffa.option.homeCountry.textDescription'),
                'size' => 'small',
                'default' => '',
            ]);
        }
    }

    /**
     * Back end script (select all buttons, file sizes). Only for logged-in
     * users and only in the back end.
     */
    protected function addAdminScript(): void
    {
        $request = Application::get()->getRequest();
        if (!$request->getUser() || !$request->getContext()) {
            return;
        }

        $config = [
            'sizeUrl' => $request->getDispatcher()->url(
                $request,
                Application::ROUTE_PAGE,
                $request->getContext()->getPath(),
                'about',
                'aboutThisPublishingSystem',
                null,
                [self::FILE_SIZE_PARAM => 1]
            ),
            'sizeParam' => self::FILE_SIZE_PARAM,
            'largeBytes' => self::LARGE_FILE_BYTES,
            'labels' => [
                'selectAll' => __('plugins.themes.manuscriptDaffa.admin.selectAll'),
                'clearSelection' => __('plugins.themes.manuscriptDaffa.admin.clearSelection'),
                'large' => __('plugins.themes.manuscriptDaffa.admin.largeFile'),
            ],
        ];

        $this->addScript(
            'manuscriptDaffaAdminConfig',
            'window.manuscriptDaffaAdmin = ' . json_encode($config, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) . ';',
            ['inline' => true, 'contexts' => 'backend']
        );
        $this->addScript('manuscriptDaffaAdmin', 'js/manuscriptDaffaAdmin.js', ['contexts' => 'backend']);
    }

    /*
     * Page handling
     */

    /**
     * Prepare the data of the page and register the output filter.
     *
     * @hook TemplateManager::display [[$templateMgr, &$template, &$output]]
     */
    public function handleDisplay(string $hookName, array $args): bool
    {
        /** @var TemplateManager $templateMgr */
        $templateMgr = $args[0];
        $template = (string) $args[1];
        $request = Application::get()->getRequest();

        // Back end file size lookup (answered instead of the page)
        if ($request->getUserVar(self::FILE_SIZE_PARAM) !== null) {
            $output = &$args[2];
            $output = $this->fileSizeResponse();
            return Hook::ABORT;
        }

        if (!str_starts_with($template, 'frontend/')) {
            return Hook::CONTINUE;
        }

        $context = $request->getContext();
        $this->page = [
            'template' => $template,
            'context' => $context,
            'description' => null,
            'diagnostics' => [],
        ];

        try {
            switch ($template) {
                case 'frontend/pages/article.tpl':
                    $this->prepareArticle($templateMgr);
                    break;
                case 'frontend/pages/issue.tpl':
                    $this->prepareIssue($templateMgr, false);
                    break;
                case 'frontend/pages/indexJournal.tpl':
                    $this->prepareIssue($templateMgr, true);
                    $this->prepareCategories($templateMgr);
                    break;
                case 'frontend/pages/issueArchive.tpl':
                    $this->prepareArchive($templateMgr);
                    break;
            }
            if ($this->page['description'] === null && $context) {
                $this->page['description'] = trim($context->getLocalizedName() . '. ' . Html::excerpt($context->getLocalizedData('description'), 400), ' .');
            }
        } catch (Throwable $e) {
            error_log('manuscriptDaffa: ' . $e->getMessage());
        }

        $templateMgr->registerFilter('output', $this->filterOutput(...));

        return Hook::CONTINUE;
    }

    /**
     * JSON answer of the back end file size lookup.
     */
    protected function fileSizeResponse(): string
    {
        $request = Application::get()->getRequest();
        $sizes = null;
        try {
            $sizes = FileSizes::forSubmission((int) $request->getUserVar('submissionId'));
        } catch (Throwable $e) {
            error_log('manuscriptDaffa file sizes: ' . $e->getMessage());
        }
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store, private');
            header('X-Robots-Tag: noindex');
            if ($sizes === null) {
                http_response_code(403);
            }
        }
        return json_encode(['sizes' => (object) ($sizes ?? [])]);
    }

    protected function prepareArticle(TemplateManager $templateMgr): void
    {
        $publication = $templateMgr->getTemplateVars('publication');
        if (!$publication) {
            return;
        }

        $authors = [];
        foreach ($publication->getData('authors') ?? [] as $author) {
            $code = strtoupper(trim((string) $author->getCountry()));
            $authors[] = [
                'name' => $author->getFullName(),
                'country' => $code !== '' ? Countries::name($code) : '',
            ];
        }
        $this->page['authors'] = $authors;
        $this->page['doi'] = $this->doiOf($publication);

        $abstract = $publication->getLocalizedData('abstract');
        if (trim(strip_tags((string) $abstract)) !== '') {
            $this->page['description'] = Html::excerpt($abstract);
        } else {
            $this->page['description'] = Html::excerpt($publication->getLocalizedFullTitle());
        }
    }

    /**
     * Issue page and homepage: sort by pages, coverage box, article actions,
     * folding of the homepage list.
     */
    protected function prepareIssue(TemplateManager $templateMgr, bool $isHome): void
    {
        $issue = $templateMgr->getTemplateVars('issue');
        $publishedSubmissions = $templateMgr->getTemplateVars('publishedSubmissions');
        $context = $this->page['context'];
        if (!$issue || !$context) {
            return;
        }

        if (is_array($publishedSubmissions)) {
            $publishedSubmissions = IssueStats::sortByPages($publishedSubmissions);
            $templateMgr->assign('publishedSubmissions', $publishedSubmissions);

            $order = [];
            foreach ($publishedSubmissions as $section) {
                foreach ($section['articles'] ?? [] as $article) {
                    $this->submissions[(int) $article->getId()] = $article;
                    $order[] = (int) $article->getId();
                }
            }
            if ($isHome && count($order) > self::HOME_ARTICLE_LIMIT) {
                $this->page['hiddenArticles'] = array_fill_keys(array_slice($order, self::HOME_ARTICLE_LIMIT), true);
                $this->page['issueUrl'] = $this->url('issue', 'view', [$issue->getBestIssueId()]);
            }
        }

        if ($issue->getPublished()) {
            $stats = IssueStats::get((int) $context->getId(), (int) $issue->getId(), $this->getHomeCountry());
            $this->page['coverage'] = $stats;
            $this->page['diagnostics'][] = 'countries taken from the affiliation: ' . $stats['guessed'];
        }

        if (!$isHome) {
            $parts = array_filter([
                $issue->getIssueIdentification(),
                Html::excerpt($issue->getLocalizedDescription(), 400),
            ]);
            $this->page['description'] = Html::excerpt(implode('. ', $parts));
        }
    }

    /**
     * Category cards of the homepage: categories with articles in the
     * current issue.
     */
    protected function prepareCategories(TemplateManager $templateMgr): void
    {
        $context = $this->page['context'];
        $issue = $templateMgr->getTemplateVars('issue');
        if (!$context || !$issue || !$this->submissions) {
            $this->page['diagnostics'][] = 'categories with articles in current issue: 0 (no current issue)';
            return;
        }

        $categories = [];
        foreach (Repo::category()->getCollector()->filterByContextIds([$context->getId()])->getMany() as $category) {
            $categories[(int) $category->getId()] = $category;
        }
        if (!$categories) {
            $this->page['diagnostics'][] = 'categories with articles in current issue: 0 (the journal has no categories)';
            return;
        }

        $cards = [];
        foreach ($this->submissions as $submission) {
            $publication = $submission->getCurrentPublication();
            foreach ((array) ($publication?->getData('categoryIds') ?? []) as $categoryId) {
                $categoryId = (int) $categoryId;
                if (!isset($categories[$categoryId])) {
                    continue;
                }
                $cards[$categoryId]['category'] = $categories[$categoryId];
                $cards[$categoryId]['articles'][] = [
                    'title' => $publication->getLocalizedFullTitle(null, 'html'),
                    'url' => $this->articleUrl($submission),
                ];
            }
        }

        // Keep the order of the categories in the journal settings
        $ordered = [];
        foreach ($categories as $id => $category) {
            if (isset($cards[$id])) {
                $ordered[] = $cards[$id];
            }
        }
        $this->page['categories'] = $ordered;
        $this->page['diagnostics'][] = 'categories with articles in current issue: ' . count($ordered) . ' of ' . count($categories);
    }

    protected function prepareArchive(TemplateManager $templateMgr): void
    {
        $issues = $templateMgr->getTemplateVars('issues');
        $context = $this->page['context'];
        if (!$context || !is_iterable($issues)) {
            return;
        }
        if ($this->authorOriginsActive()) {
            $this->page['diagnostics'][] = 'archive cards: skipped, Author Origins plugin is active';
            return;
        }
        $home = $this->getHomeCountry();
        $stats = [];
        foreach ($issues as $issue) {
            $stats[] = IssueStats::get((int) $context->getId(), (int) $issue->getId(), $home);
        }
        $this->page['archive'] = $stats;
    }

    /**
     * Is a generic plugin that looks like Author Origins enabled?
     */
    protected function authorOriginsActive(): bool
    {
        foreach (PluginRegistry::getPlugins('generic') ?? [] as $name => $plugin) {
            if (stripos((string) $name, 'authororigin') !== false && method_exists($plugin, 'getEnabled') && $plugin->getEnabled()) {
                return true;
            }
        }
        return false;
    }

    /*
     * Output filter
     */

    /**
     * Change the rendered page. Only full HTML pages are touched (sidebar
     * blocks and other fragments rendered with fetch() are left alone).
     */
    public function filterOutput(string $html, $smartyTemplate = null): string
    {
        if (!str_contains($html, '</body>') || !str_contains($html, '<html')) {
            return $html;
        }
        if (!empty($this->page['done'])) {
            return $html;
        }
        $this->page['done'] = true;

        try {
            $template = $this->page['template'] ?? '';

            if ($template === 'frontend/pages/article.tpl') {
                $html = $this->filterArticleAuthors($html);
                $html = $this->filterDimensions($html);
            }
            if (in_array($template, ['frontend/pages/issue.tpl', 'frontend/pages/indexJournal.tpl'])) {
                $html = $this->filterCoverage($html);
            }
            if ($template === 'frontend/pages/indexJournal.tpl') {
                $html = $this->filterHomeFold($html);
                $html = $this->filterCategories($html);
            }
            if ($template === 'frontend/pages/issueArchive.tpl') {
                $html = $this->filterArchive($html);
            }
            if (str_contains($html, '<div class="obj_article_summary">')) {
                $html = $this->filterArticleList($html);
            }
            $html = $this->filterImages($html);
            $html = $this->filterMetaDescription($html);
            $html = $this->filterDiagnostics($html);
        } catch (Throwable $e) {
            error_log('manuscriptDaffa output: ' . $e->getMessage());
        }

        return $html;
    }

    /**
     * Article page: add the country after the affiliation of each author.
     */
    protected function filterArticleAuthors(string $html): string
    {
        $authors = $this->page['authors'] ?? [];
        if (!$authors) {
            return $html;
        }
        $start = strpos($html, '<section class="item authors">');
        if ($start === false) {
            return $html;
        }
        $ulStart = strpos($html, '<ul class="authors">', $start);
        if ($ulStart === false) {
            return $html;
        }
        $ulEnd = Html::elementEnd($html, $ulStart, 'ul');
        if ($ulEnd === null) {
            return $html;
        }
        $list = substr($html, $ulStart, $ulEnd - $ulStart);

        // Split into <li> items (the list has no nested lists)
        $items = preg_split('~(?=<li>)~', $list);
        $byName = [];
        foreach ($authors as $i => $author) {
            $byName[$this->nameKey($author['name'])][] = $i;
        }
        $used = [];
        $index = 0;
        foreach ($items as $k => $item) {
            if (!str_starts_with($item, '<li>')) {
                continue;
            }
            $author = null;
            if (preg_match('~<span class="name">\s*(.*?)\s*</span>~s', $item, $m)) {
                $key = $this->nameKey(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                // Same position and same name, otherwise the first unused author with that name
                if (isset($authors[$index]) && $this->nameKey($authors[$index]['name']) === $key && empty($used[$index])) {
                    $author = $index;
                } else {
                    foreach ($byName[$key] ?? [] as $candidate) {
                        if (empty($used[$candidate])) {
                            $author = $candidate;
                            break;
                        }
                    }
                }
            }
            $index++;
            if ($author === null) {
                continue;
            }
            $used[$author] = true;
            $country = $authors[$author]['country'];
            if ($country === '') {
                continue;
            }

            $affStart = strpos($item, '<span class="affiliation">');
            if ($affStart !== false && ($affEnd = Html::elementEnd($item, $affStart, 'span')) !== null) {
                $closing = $affEnd - strlen('</span>');
                $items[$k] = substr($item, 0, $closing)
                    . '<span class="msd-country">' . Html::e(__('common.commaListSeparator') ?: ', ') . Html::e($country) . '</span>'
                    . substr($item, $closing);
            } elseif (preg_match('~<span class="name">.*?</span>~s', $item, $m, PREG_OFFSET_CAPTURE)) {
                $pos = $m[0][1] + strlen($m[0][0]);
                $items[$k] = substr($item, 0, $pos)
                    . '<span class="affiliation msd-country-only">' . Html::e($country) . '</span>'
                    . substr($item, $pos);
            }
        }

        return substr($html, 0, $ulStart) . implode('', $items) . substr($html, $ulEnd);
    }

    protected function nameKey(string $name): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $name)), 'UTF-8');
    }

    /**
     * Article page: Dimensions citation badge above the DOI.
     */
    protected function filterDimensions(string $html): string
    {
        $doi = $this->page['doi'] ?? null;
        if (!$doi || !str_contains($html, '<section class="item doi">')) {
            return $html;
        }
        $badge = '<section class="item msd-dimensions">'
            . '<span class="__dimensions_badge_embed__" data-doi="' . Html::e($doi) . '" data-style="small_rectangle" data-hide-zero-citations="false"></span>'
            . '</section>';
        $html = Html::insertBefore($html, '<section class="item doi">', $badge);
        return Html::insertBefore(
            $html,
            '</body>',
            '<script async src="https://badge.dimensions.ai/badge.js" charset="utf-8"></script>' . "\n"
        );
    }

    /**
     * Issue page and homepage: "Issue Coverage" box next to the cover.
     */
    protected function filterCoverage(string $html): string
    {
        $stats = $this->page['coverage'] ?? null;
        if (!$stats || !$stats['articles']) {
            return $html;
        }
        $tocStart = strpos($html, '<div class="obj_issue_toc">');
        if ($tocStart === false) {
            return $html;
        }
        $headingStart = strpos($html, '<div class="heading">', $tocStart);
        if ($headingStart === false) {
            return $html;
        }
        $insertAt = $headingStart + strlen('<div class="heading">');
        if (preg_match('~\G\s*<div class="cover">~', $html, $m, 0, $insertAt)) {
            $coverStart = strpos($html, '<div class="cover">', $insertAt);
            $coverEnd = Html::elementEnd($html, $coverStart, 'div');
            if ($coverEnd !== null) {
                $insertAt = $coverEnd;
            }
        }
        return substr($html, 0, $insertAt) . $this->coverageBox($stats) . substr($html, $insertAt);
    }

    protected function coverageBox(array $stats): string
    {
        $out = '<div class="msd-coverage">';
        $out .= '<div class="msd-coverage-title">' . Html::e(__('plugins.themes.manuscriptDaffa.coverage.title', [
            'authors' => $stats['authors'],
            'countries' => count($stats['countries']),
        ])) . '</div>';
        $out .= '<ul class="msd-coverage-stats">';
        $out .= '<li>' . Html::e(__('plugins.themes.manuscriptDaffa.coverage.articles', ['count' => $stats['articles']])) . '</li>';
        if ($stats['pages'] !== null) {
            $out .= '<li>' . Html::e(__('plugins.themes.manuscriptDaffa.coverage.pages', ['count' => $stats['pages']])) . '</li>';
        }
        if ($stats['foreignPct'] !== null) {
            $out .= '<li>' . Html::e(__('plugins.themes.manuscriptDaffa.coverage.foreign', [
                'count' => $stats['foreign'],
                'percent' => $stats['foreignPct'],
            ])) . '</li>';
        }
        $out .= '</ul>';
        if ($stats['countries']) {
            $out .= '<ul class="msd-coverage-countries">';
            foreach ($stats['countries'] as $code => $count) {
                $name = Countries::name($code);
                $out .= '<li>' . $this->flag($code, $name)
                    . '<span class="msd-country-name">' . Html::e($name) . '</span> '
                    . '<span class="msd-country-count">' . Html::e(__('plugins.themes.manuscriptDaffa.coverage.authorCount', ['count' => $count])) . '</span></li>';
            }
            $out .= '</ul>';
        }
        return $out . '</div>';
    }

    protected function flag(string $code, string $name): string
    {
        $request = Application::get()->getRequest();
        $url = Countries::flagUrl($code, $request->getBaseUrl() . '/' . $this->getPluginPath());
        if (!$url) {
            return '';
        }
        return '<img class="msd-flag" src="' . Html::e($url) . '" alt="" title="' . Html::e($name) . '" width="20" height="15" loading="lazy" decoding="async">';
    }

    /**
     * Homepage: hide the articles after HOME_ARTICLE_LIMIT and add the
     * "Read more" button.
     */
    protected function filterHomeFold(string $html): string
    {
        $hidden = $this->page['hiddenArticles'] ?? [];
        if (!$hidden) {
            return $html;
        }
        $start = strpos($html, '<div class="sections">');
        $endMarker = '</div><!-- .sections -->';
        if ($start === false || ($end = strpos($html, $endMarker, $start)) === false) {
            return $html;
        }
        $sections = substr($html, $start, $end - $start);

        // Hide the list items
        $sections = preg_replace_callback(
            '~<li>(\s*<div class="obj_article_summary">.*?\bid="article-(\d+)")~s',
            fn ($m) => isset($hidden[(int) $m[2]]) ? '<li class="msd-more" hidden>' . $m[1] : $m[0],
            $sections
        );

        // Hide sections whose articles are all hidden, with their title
        $chunks = explode('<div class="section">', $sections);
        foreach ($chunks as $i => $chunk) {
            if ($i === 0) {
                continue;
            }
            preg_match_all('~<a id="article-(\d+)"~', $chunk, $m);
            $ids = array_map('intval', $m[1]);
            $allHidden = $ids && !array_diff_key(array_flip($ids), $hidden);
            $chunks[$i] = ($allHidden ? '<div class="section msd-more-section" hidden>' : '<div class="section">') . $chunk;
        }
        $sections = $chunks[0] . implode('', array_slice($chunks, 1));

        $button = '<div class="msd-readmore-wrap"><a class="msd-readmore" href="' . Html::e($this->page['issueUrl'] ?? '#') . '"'
            . ' data-label-more="' . Html::e(__('plugins.themes.manuscriptDaffa.home.readMore')) . '"'
            . ' data-label-less="' . Html::e(__('plugins.themes.manuscriptDaffa.home.showLess')) . '"'
            . ' aria-expanded="false">' . Html::e(__('plugins.themes.manuscriptDaffa.home.readMore')) . '</a></div>';

        return substr($html, 0, $start) . $sections . $endMarker . $button . substr($html, $end + strlen($endMarker));
    }

    /**
     * Homepage: category cards under the About section.
     */
    protected function filterCategories(string $html): string
    {
        $cards = $this->page['categories'] ?? [];
        if (!$cards) {
            return $html;
        }

        $out = '<section class="msd-categories"><h2>' . Html::e(__('plugins.themes.manuscriptDaffa.categories.title')) . '</h2><div class="msd-category-grid">';
        foreach ($cards as $card) {
            $count = count($card['articles']);
            $out .= '<details class="msd-category"><summary>'
                . '<span class="msd-category-name">' . Html::e($card['category']->getLocalizedTitle()) . '</span>'
                . '<span class="msd-category-count">' . Html::e(__('plugins.themes.manuscriptDaffa.categories.articles', ['count' => $count])) . '</span>'
                . '</summary><ul>';
            foreach (array_slice($card['articles'], 0, self::CATEGORY_ARTICLE_LIMIT) as $article) {
                $out .= '<li><a href="' . Html::e($article['url']) . '">' . PKPString::stripUnsafeHtml($article['title']) . '</a></li>';
            }
            $out .= '</ul>';
            if ($count > self::CATEGORY_ARTICLE_LIMIT) {
                $out .= '<a class="msd-category-all" href="' . Html::e($this->url('catalog', 'category', [$card['category']->getPath()])) . '">'
                    . Html::e(__('plugins.themes.manuscriptDaffa.categories.viewAll')) . '</a>';
            }
            $out .= '</details>';
        }
        $out .= '</div></section>';

        $pageStart = strpos($html, '<div class="page_index_journal">');
        if ($pageStart === false) {
            return $html;
        }

        // The block that follows About: announcements, the current issue,
        // additional content or the end of the page.
        $next = null;
        foreach (['<section class="cmp_announcements', '<section class="current_issue">', '<div class="additional_content">', '</div><!-- .page -->'] as $needle) {
            $pos = strpos($html, $needle, $pageStart);
            if ($pos !== false && ($next === null || $pos < $next)) {
                $next = $pos;
            }
        }
        if ($next === null) {
            return $html;
        }

        $aboutStart = strpos($html, '<section class="homepage_about">', $pageStart);
        if ($aboutStart !== false && $aboutStart < $next) {
            $aboutEnd = strrpos(substr($html, 0, $next), '</section>');
            if ($aboutEnd !== false && $aboutEnd > $aboutStart) {
                $at = $aboutEnd + strlen('</section>');
                return substr($html, 0, $at) . $out . substr($html, $at);
            }
        }

        return substr($html, 0, $next) . $out . substr($html, $next);
    }

    /**
     * Archives: statistic cards and lazy-loaded covers.
     */
    protected function filterArchive(string $html): string
    {
        $parts = explode('<div class="obj_issue_summary">', $html);
        $count = count($parts) - 1;
        if ($count < 1) {
            return $html;
        }

        // Covers: lazy loading except the first one
        for ($i = 2; $i <= $count; $i++) {
            $parts[$i] = preg_replace_callback(
                '~^(\s*<a class="cover"[^>]*>\s*)(<img\b[^>]*>)~',
                fn ($m) => $m[1] . Html::addAttribute($m[2], ['img'], 'loading', 'lazy'),
                $parts[$i]
            );
        }

        $stats = $this->page['archive'] ?? null;
        $hasOtherCards = stripos($html, 'author-origins') !== false || stripos($html, 'authorOrigins') !== false;
        if ($stats && count($stats) === $count && !$hasOtherCards) {
            for ($i = 1; $i <= $count; $i++) {
                $pos = strpos($parts[$i], '</h2>');
                if ($pos === false) {
                    continue;
                }
                $pos += strlen('</h2>');
                $parts[$i] = substr($parts[$i], 0, $pos) . $this->archiveCard($stats[$i - 1]) . substr($parts[$i], $pos);
            }
        }

        return implode('<div class="obj_issue_summary">', $parts);
    }

    protected function archiveCard(array $stats): string
    {
        if (!$stats['articles']) {
            return '';
        }
        $out = '<div class="msd-archive-stats">';

        $out .= '<div class="msd-stat"><span class="msd-stat-num">' . (int) $stats['authors'] . '</span>'
            . '<span class="msd-stat-label">' . Html::e(__('plugins.themes.manuscriptDaffa.archive.authors')) . '</span>';
        if ($stats['foreignPct'] !== null) {
            $out .= '<span class="msd-stat-sub">' . Html::e(__('plugins.themes.manuscriptDaffa.archive.foreign', ['percent' => $stats['foreignPct']])) . '</span>';
        }
        $out .= '</div>';

        $out .= '<div class="msd-stat"><span class="msd-stat-num">' . count($stats['countries']) . '</span>'
            . '<span class="msd-stat-label">' . Html::e(__('plugins.themes.manuscriptDaffa.archive.countries')) . '</span>';
        if ($stats['countries']) {
            $out .= '<span class="msd-stat-sub msd-flags">';
            $shown = 0;
            foreach ($stats['countries'] as $code => $n) {
                if ($shown >= self::ARCHIVE_FLAG_LIMIT) {
                    break;
                }
                $out .= $this->flag($code, Countries::name($code));
                $shown++;
            }
            $rest = count($stats['countries']) - $shown;
            if ($rest > 0) {
                $out .= '<span class="msd-flags-more">+' . $rest . '</span>';
            }
            $out .= '</span>';
        }
        $out .= '</div>';

        $out .= '<div class="msd-stat"><span class="msd-stat-num">' . (int) $stats['articles'] . '</span>'
            . '<span class="msd-stat-label">' . Html::e(__('plugins.themes.manuscriptDaffa.archive.articles')) . '</span>';
        if ($stats['pages'] !== null) {
            $out .= '<span class="msd-stat-sub">' . Html::e(__('plugins.themes.manuscriptDaffa.archive.pages', ['count' => $stats['pages']])) . '</span>';
        }
        $out .= '</div>';

        return $out . '</div>';
    }

    /**
     * Article lists (issue, homepage, search): action row and abstract,
     * PDF button moved into "Download PDF".
     */
    protected function filterArticleList(string $html): string
    {
        $parts = explode('<div class="obj_article_summary">', $html);
        for ($i = 1; $i < count($parts); $i++) {
            $chunk = $parts[$i];
            if (!preg_match('~\bid="article-(\d+)"~', $chunk, $m)) {
                continue;
            }
            $id = (int) $m[1];
            $data = $this->listData($id);
            if (!$data) {
                continue;
            }

            // Take the PDF galley button of this article out of the list
            $pdfUrl = null;
            $galleyPattern = '~<li>\s*<a class="obj_galley_link pdf[^"]*" href="([^"]+)"[^>]*\bid="article-' . $id . '-galley-\d+"[^>]*>.*?</a>\s*</li>~s';
            if (preg_match($galleyPattern, $chunk, $g)) {
                $pdfUrl = html_entity_decode($g[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $chunk = preg_replace($galleyPattern, '', $chunk, 1);
                $chunk = preg_replace('~<ul class="galleys_links">\s*</ul>~', '', $chunk, 1);
            }
            $pdfUrl = $pdfUrl ?: $data['pdf'];

            $row = $this->actionRow($id, $data, $pdfUrl);

            // After the meta block (authors, pages), otherwise after the title
            $at = null;
            $metaStart = strpos($chunk, '<div class="meta">');
            if ($metaStart !== false) {
                $at = Html::elementEnd($chunk, $metaStart, 'div');
            }
            if ($at === null && preg_match('~</h[1-6]>~', $chunk, $h, PREG_OFFSET_CAPTURE)) {
                $at = $h[0][1] + strlen($h[0][0]);
            }
            if ($at === null) {
                continue;
            }
            $parts[$i] = substr($chunk, 0, $at) . $row . substr($chunk, $at);
        }
        return implode('<div class="obj_article_summary">', $parts);
    }

    protected function actionRow(int $id, array $data, ?string $pdfUrl): string
    {
        $links = [];
        $abstract = '';
        if ($data['abstract'] !== '') {
            $links[] = '<a class="msd-act msd-act-abstract" href="' . Html::e($data['url']) . '" aria-expanded="false" aria-controls="msd-abstract-' . $id . '"'
                . ' data-label-show="' . Html::e(__('plugins.themes.manuscriptDaffa.list.showAbstract')) . '"'
                . ' data-label-hide="' . Html::e(__('plugins.themes.manuscriptDaffa.list.hideAbstract')) . '">'
                . Html::e(__('plugins.themes.manuscriptDaffa.list.showAbstract')) . '</a>';
            $abstract = '<div class="msd-abstract" id="msd-abstract-' . $id . '" hidden>' . $data['abstract'] . '</div>';
        }
        if ($pdfUrl) {
            $links[] = '<a class="msd-act msd-act-pdf" href="' . Html::e($pdfUrl) . '">' . Html::e(__('plugins.themes.manuscriptDaffa.list.downloadPdf')) . '</a>';
        }
        if ($data['doi']) {
            $links[] = '<a class="msd-act msd-act-doi" href="https://doi.org/' . Html::e($this->doiPath($data['doi'])) . '" target="_blank" rel="noopener">DOI: ' . Html::e($data['doi']) . '</a>';
        }
        if ($data['title'] !== '') {
            $links[] = '<a class="msd-act msd-act-scholar" href="https://scholar.google.com/scholar?q=' . rawurlencode('"' . $data['title'] . '"') . '" target="_blank" rel="noopener">'
                . Html::e(__('plugins.themes.manuscriptDaffa.list.scholar')) . '</a>';
        }
        if (!$links) {
            return '';
        }
        return '<div class="msd-actions">' . implode(' ', $links) . '</div>' . $abstract;
    }

    /**
     * Data of an article in a list, or null when it is not a published article.
     */
    protected function listData(int $id): ?array
    {
        $submission = $this->submissions[$id] ?? null;
        if (!$submission) {
            $submission = Repo::submission()->get($id);
            if (!$submission || (int) $submission->getData('status') !== Submission::STATUS_PUBLISHED) {
                return null;
            }
            $this->submissions[$id] = $submission;
        }
        $publication = $submission->getCurrentPublication();
        if (!$publication) {
            return null;
        }

        $context = $this->page['context'];
        $contextPath = null;
        if (!$context || (int) $context->getId() !== (int) $submission->getData('contextId')) {
            $other = Application::getContextDAO()->getById((int) $submission->getData('contextId'));
            $contextPath = $other?->getPath();
        }

        $pdf = null;
        foreach ($publication->getData('galleys') ?? [] as $galley) {
            if (!$galley->getData('urlRemote') && $galley->isPdfGalley()) {
                $pdf = $this->url('article', 'download', [$this->articlePath($submission), $galley->getBestGalleyId()], $contextPath);
                break;
            }
        }

        $abstract = (string) $publication->getLocalizedData('abstract');
        $title = trim(html_entity_decode(strip_tags((string) $publication->getLocalizedFullTitle()), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return [
            'url' => $this->articleUrl($submission, $contextPath),
            'abstract' => trim(strip_tags($abstract)) !== '' ? PKPString::stripUnsafeHtml($abstract) : '',
            'pdf' => $pdf,
            'doi' => $this->doiOf($publication),
            'title' => $title,
        ];
    }

    /**
     * Images: header logo first, lazy sidebar and footer media (not counters).
     */
    protected function filterImages(string $html): string
    {
        $html = Html::region($html, '<div class="pkp_site_name">', '</div>', function ($region) {
            return preg_replace_callback('~<img\b[^>]*>~i', fn ($m) => Html::addAttribute($m[0], ['img'], 'fetchpriority', 'high'), $region, 1);
        });

        $skip = fn (string $tag) => (bool) preg_match(self::COUNTER_PATTERN, $tag);
        $lazy = fn (string $region) => Html::addAttribute($region, ['img', 'iframe'], 'loading', 'lazy', $skip);

        $html = Html::region($html, '<div class="pkp_structure_sidebar', '</div><!-- pkp_sidebar.left -->', $lazy);
        $html = Html::region($html, '<div class="pkp_structure_footer_wrapper"', '</div><!-- pkp_structure_footer_wrapper -->', $lazy);

        return $html;
    }

    /**
     * <meta name="description"> for pages that have none.
     */
    protected function filterMetaDescription(string $html): string
    {
        $headEnd = stripos($html, '</head>');
        if ($headEnd === false) {
            return $html;
        }
        $head = substr($html, 0, $headEnd);
        if (preg_match('~<meta\s[^>]*name\s*=\s*["\']description["\']~i', $head)) {
            return $html;
        }
        $description = Html::excerpt($this->page['description'] ?? '');
        if ($description === '') {
            return $html;
        }
        return substr($html, 0, $headEnd) . '<meta name="description" content="' . Html::e($description) . '">' . "\n" . substr($html, $headEnd);
    }

    /**
     * A diagnostic comment for journal managers and admins.
     */
    protected function filterDiagnostics(string $html): string
    {
        $request = Application::get()->getRequest();
        $user = $request->getUser();
        $context = $this->page['context'] ?? null;
        if (!$user || !$context || !$user->hasRole([Role::ROLE_ID_MANAGER, Role::ROLE_ID_SITE_ADMIN], (int) $context->getId())) {
            return $html;
        }
        $lines = $this->page['diagnostics'] ?? [];
        $lines[] = 'home country: ' . ($this->getHomeCountry() ?: 'not set');
        $comment = "\n<!-- manuscriptDaffa " . $this->getVersionString() . "\n  " . implode("\n  ", array_map(
            fn ($l) => str_replace('--', '- -', $l),
            $lines
        )) . "\n-->\n";
        return Html::insertBefore($html, '</body>', $comment);
    }

    /*
     * Helpers
     */

    protected function getVersionString(): string
    {
        $version = @simplexml_load_file($this->getPluginPath() . '/version.xml');
        return $version ? (string) $version->release : '';
    }

    protected function url(string $page, string $op, array $path = [], ?string $contextPath = null): string
    {
        $request = Application::get()->getRequest();
        $context = $request->getContext();
        return $request->getDispatcher()->url(
            $request,
            Application::ROUTE_PAGE,
            $contextPath ?? $context?->getPath(),
            $page,
            $op,
            array_map('strval', $path)
        );
    }

    protected function articlePath(Submission $submission): string
    {
        $publication = $submission->getCurrentPublication();
        return (string) ($publication?->getData('urlPath') ?: $submission->getId());
    }

    protected function articleUrl(Submission $submission, ?string $contextPath = null): string
    {
        return $this->url('article', 'view', [$this->articlePath($submission)], $contextPath);
    }

    protected function doiOf($publication): ?string
    {
        try {
            $doi = method_exists($publication, 'getDoi') ? $publication->getDoi() : null;
            if (!$doi && ($doiObject = $publication->getData('doiObject'))) {
                $doi = $doiObject->getData('doi');
            }
        } catch (Throwable $e) {
            $doi = null;
        }
        $doi = trim((string) $doi);
        return $doi !== '' ? $doi : null;
    }

    /**
     * DOI for a doi.org URL: keep "/" readable, encode the rest.
     */
    protected function doiPath(string $doi): string
    {
        return str_replace('%2F', '/', rawurlencode($doi));
    }
}
