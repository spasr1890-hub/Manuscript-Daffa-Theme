<?php

/**
 * @file plugins/themes/manuscriptDaffa/ManuscriptDaffaThemePlugin.inc.php
 *
 * Copyright (c) 2014-2020 Simon Fraser University
 * Copyright (c) 2003-2020 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class ManuscriptDaffaThemePlugin
 *
 * @brief Manuscript-Daffa: the Manuscript child theme of the Default Theme
 *  with extra features for journals. No core template is copied: the theme
 *  prepares data when a page is displayed (handleDisplay) and changes the
 *  rendered HTML of the Default Theme templates with a Smarty output filter.
 *
 *  This is the OJS 3.3 version (PHP 7.3 and newer). The OJS 3.4/3.5 version
 *  lives in the repository root; both share js/, flags/, fonts/ and
 *  styles/manuscriptDaffa.css.
 */

import('lib.pkp.classes.plugins.ThemePlugin');

require_once __DIR__ . '/classes/MsdHtml.inc.php';
require_once __DIR__ . '/classes/MsdCountries.inc.php';
require_once __DIR__ . '/classes/MsdIssueStats.inc.php';
require_once __DIR__ . '/classes/MsdFileSizes.inc.php';
require_once __DIR__ . '/classes/MsdSideMenu.inc.php';

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

    /**
     * Features that can be switched off in Website > Appearance > Theme.
     * A feature added in a later version starts switched on, also for
     * journals that saved the list before (see featureEnabled()).
     */
    public const FEATURES = [
        'authorCountry',
        'dimensions',
        'coverage',
        'sortByPages',
        'homeFold',
        'categories',
        'archiveCards',
        'actionRows',
        'metaDescription',
        'openGraph',
        'imageLoading',
        'adminSelectAll',
        'adminFileSizes',
    ];

    /** Request parameter for managers: recompute the issue statistics now */
    public const REFRESH_PARAM = 'manuscriptDaffaRefresh';

    /** Article list text size option: value => scale factor */
    public const ARTICLE_LIST_SIZES = ['small' => 0.9, 'medium' => 1, 'large' => 1.12, 'xlarge' => 1.25];

    /** Choices of the header logo height option (desktop) */
    public const HEADER_LOGO_HEIGHTS = ['80', '100', '120', '150', 'full'];

    /** Choices of the header logo position option (desktop) */
    public const HEADER_LOGO_POSITIONS = ['left', 'center', 'right'];

    /** Choices of the sidebar menu style option */
    public const SIDEBAR_MENU_STYLES = ['soft', 'solid', 'off'];

    /** Font preloaded on public pages (must match the URL in styles/fonts.less) */
    public const PRELOAD_FONT = 'fonts/montserrat-v15-vietnamese_latin-ext_latin_cyrillic-ext_cyrillic-700.woff2';

    /** Script of the Dimensions citation badge */
    public const DIMENSIONS_SCRIPT = 'https://badge.dimensions.ai/badge.js';

    /** Request parameter of the back end file size lookup */
    public const FILE_SIZE_PARAM = 'manuscriptDaffaFileSizes';

    /** Image hosts of visitor counters that must not be lazy-loaded */
    public const COUNTER_PATTERN = '~flagcounter|statcounter|histats|clustrmaps|whos\.amung|revolvermaps|hitwebcounter|freecounter|free-counter|counter\.|/counter|supercounters|amazingcounters|website-hit-counters|visitor-?map|livetraffic|statsy|hitcounter~i';

    /** @var array Data collected in handleDisplay for the output filter */
    protected $page = [];

    /** @var ?string[] Enabled features, see featureEnabled() */
    protected $enabledFeatures = null;

    /** @var array<int,Submission> Submissions already loaded by the page */
    protected $submissions = [];

    /**
     * @copydoc ThemePlugin::init
     */
    public function init()
    {
        // Register first, so a failure further down can not leave the page
        // unprocessed, and early, so the output filter is in place even when
        // a later plugin renders the page itself.
        HookRegistry::register('TemplateManager::display', [$this, 'handleDisplay'], HOOK_SEQUENCE_CORE);

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

        // Features that can be switched off
        $this->addOption('features', 'FieldOptions', [
            'type' => 'checkbox',
            'label' => __('plugins.themes.manuscriptDaffa.option.features.label'),
            'description' => __('plugins.themes.manuscriptDaffa.option.features.description'),
            'options' => $this->optionList('features', self::FEATURES),
            'default' => self::FEATURES,
        ]);

        // Text size of article lists (title, authors, buttons, abstract)
        $this->addOption('articleListSize', 'FieldOptions', [
            'type' => 'radio',
            'label' => __('plugins.themes.manuscriptDaffa.option.articleListSize.label'),
            'description' => __('plugins.themes.manuscriptDaffa.option.articleListSize.description'),
            'options' => $this->optionList('articleListSize', array_keys(self::ARTICLE_LIST_SIZES)),
            'default' => 'medium',
        ]);

        // Height of the header logo on desktop screens
        $this->addOption('headerLogoHeight', 'FieldOptions', [
            'type' => 'radio',
            'label' => __('plugins.themes.manuscriptDaffa.option.headerLogoHeight.label'),
            'description' => __('plugins.themes.manuscriptDaffa.option.headerLogoHeight.description'),
            'options' => $this->optionList('headerLogoHeight', self::HEADER_LOGO_HEIGHTS),
            'default' => '80',
        ]);

        // Position of the header logo on desktop screens
        $this->addOption('headerLogoPosition', 'FieldOptions', [
            'type' => 'radio',
            'label' => __('plugins.themes.manuscriptDaffa.option.headerLogoPosition.label'),
            'description' => __('plugins.themes.manuscriptDaffa.option.headerLogoPosition.description'),
            'options' => $this->optionList('headerLogoPosition', self::HEADER_LOGO_POSITIONS),
            'default' => 'left',
        ]);

        // Lists of links in the sidebar blocks shown as a menu with icons
        $this->addOption('sidebarMenu', 'FieldOptions', [
            'type' => 'radio',
            'label' => __('plugins.themes.manuscriptDaffa.option.sidebarMenu.label'),
            'description' => __('plugins.themes.manuscriptDaffa.option.sidebarMenu.description'),
            'options' => $this->optionList('sidebarMenu', self::SIDEBAR_MENU_STYLES),
            'default' => 'soft',
        ]);

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
        $this->addStyle('manuscriptDaffa', $this->versioned('styles/manuscriptDaffa.css'));
        $this->addStyle(
            'manuscriptDaffaColours',
            ':root{--msd-primary:' . $baseColour . ';--msd-accent:' . $accentColour
                . ';--msd-on-primary:' . ($this->isColourDark($baseColour) ? '#fff' : 'rgba(0,0,0,.84)') . ';}'
                . $this->articleListSizeCss()
                . $this->headerLogoCss(),
            ['inline' => true]
        );

        // Front end behaviour (abstract toggle, "Read more")
        $this->addScript('manuscriptDaffa', $this->versioned('js/manuscriptDaffa.js'));

        // Back end helpers, only for logged-in users
        try {
            $this->addAdminScript();
        } catch (Throwable $e) {
            error_log('manuscriptDaffa admin script: ' . $e->getMessage());
        }
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
        if ($name === 'features') {
            $value = array_values(array_intersect(self::FEATURES, (array) $value));
            parent::saveOption($name, $value, $contextId);
            // Remember which features existed, so features added later start on
            if ($contextId === null) {
                $currentContext = Application::get()->getRequest()->getContext();
                $contextId = $currentContext ? $currentContext->getId() : CONTEXT_SITE;
            }
            $this->updateSetting($contextId, 'featuresKnown', self::FEATURES, 'object');
            $this->enabledFeatures = null;
            return;
        }
        if ($name === 'articleListSize' && !isset(self::ARTICLE_LIST_SIZES[(string) $value])) {
            $value = 'medium';
        }
        if ($name === 'sidebarMenu' && !in_array((string) $value, self::SIDEBAR_MENU_STYLES, true)) {
            $value = 'soft';
        }
        if ($name === 'headerLogoPosition' && !in_array((string) $value, self::HEADER_LOGO_POSITIONS, true)) {
            $value = 'left';
        }
        if ($name === 'headerLogoHeight' && !in_array((string) $value, self::HEADER_LOGO_HEIGHTS, true)) {
            $value = '80';
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

    /**
     * Options of a choice field: values with their translated labels.
     */
    protected function optionList(string $option, array $values): array
    {
        $options = [];
        foreach ($values as $value) {
            $options[] = [
                'value' => $value,
                'label' => __('plugins.themes.manuscriptDaffa.option.' . $option . '.' . $value),
            ];
        }
        return $options;
    }

    /**
     * Is a feature switched on in the theme settings?
     */
    public function featureEnabled(string $feature): bool
    {
        if ($this->enabledFeatures === null) {
            $enabled = $this->getOption('features');
            if (!is_array($enabled)) {
                $enabled = self::FEATURES;
            } else {
                $known = null;
                try {
                    $currentContext = Application::get()->getRequest()->getContext();
                $contextId = $currentContext ? $currentContext->getId() : CONTEXT_SITE;
                    $known = $this->getSetting($contextId, 'featuresKnown');
                } catch (Throwable $e) {
                    $known = null;
                }
                $known = is_array($known) ? $known : self::FEATURES;
                $enabled = array_merge($enabled, array_diff(self::FEATURES, $known));
            }
            $this->enabledFeatures = array_values(array_unique($enabled));
        }
        return in_array($feature, $this->enabledFeatures, true);
    }

    /**
     * May the current user force a refresh of the issue statistics?
     */
    protected function refreshRequested(): bool
    {
        $request = Application::get()->getRequest();
        if (!$request->getUserVar(self::REFRESH_PARAM)) {
            return false;
        }
        $user = $request->getUser();
        $context = $request->getContext();
        return $user && $context && (
            $user->hasRole([ROLE_ID_MANAGER, ROLE_ID_SITE_ADMIN], (int) $context->getId())
            || $user->hasRole([ROLE_ID_SITE_ADMIN], CONTEXT_SITE)
        );
    }

    /*
     * Setup
     */

    protected function safeColour($colour, string $default): string
    {
        return is_string($colour) && preg_match('/^#[0-9a-fA-F]{1,6}$/', $colour) ? $colour : $default;
    }

    /**
     * CSS for the article list text size option: one scale factor for the
     * title, authors, action buttons and abstract (styles/manuscriptDaffa.css).
     */
    protected function articleListSizeCss(): string
    {
        $size = (string) $this->getOption('articleListSize');
        $scale = self::ARTICLE_LIST_SIZES[$size] ?? 1;
        return $scale == 1 ? '' : ':root{--msd-list-scale:' . $scale . '}';
    }

    /**
     * CSS for the header logo options (desktop screens, 992 px and wider;
     * small screens keep the compact header of the Default Theme).
     *
     * Height:
     * - 80 (default): the Manuscript size, at most 80 px high and 75 % wide;
     * - 100/120/150: a taller logo;
     * - full: a banner across the whole header, below the user menu.
     *
     * Position (not for "full", which fills the width): left (default) keeps
     * the logo next to the user menu; center and right put it on its own row
     * below the user menu, across the whole width, so they never overlap.
     */
    protected function headerLogoCss(): string
    {
        $height = (string) $this->getOption('headerLogoHeight');
        if (!in_array($height, self::HEADER_LOGO_HEIGHTS, true)) {
            $height = '80';
        }
        $position = (string) $this->getOption('headerLogoPosition');
        if (!in_array($position, self::HEADER_LOGO_POSITIONS, true)) {
            $position = 'left';
        }

        $rules = '';
        if ($height === 'full') {
            $rules .= '.has_site_logo .pkp_head_wrapper{padding-top:3.2rem}'
                . '.pkp_site_name{width:100%}'
                . '.pkp_site_name .is_img{display:block}'
                . '.pkp_site_name .is_img img{max-height:none;width:100%;height:auto}';
        } else {
            if ($height !== '80') {
                $rules .= '.pkp_site_name .is_img img{max-height:' . (int) $height . 'px}';
            }
            if ($position !== 'left') {
                $rules .= '.has_site_logo .pkp_head_wrapper{padding-top:3.2rem}'
                    . '.pkp_site_name{width:100%;text-align:' . $position . '}';
            }
        }
        return $rules === '' ? '' : '@media (min-width:992px){' . $rules . '}';
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
            foreach (MsdCountries::all() as $code => $name) {
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
                ROUTE_PAGE,
                $request->getContext()->getPath(),
                'about',
                'aboutThisPublishingSystem',
                null,
                [self::FILE_SIZE_PARAM => 1]
            ),
            'sizeParam' => self::FILE_SIZE_PARAM,
            'largeBytes' => self::LARGE_FILE_BYTES,
            'features' => [
                'selectAll' => $this->featureEnabled('adminSelectAll'),
                'fileSizes' => $this->featureEnabled('adminFileSizes'),
            ],
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
        $this->addScript('manuscriptDaffaAdmin', $this->versioned('js/manuscriptDaffaAdmin.js'), ['contexts' => 'backend']);
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
            return true;
        }

        if (!str_starts_with($template, 'frontend/')) {
            return false;
        }

        $context = $request->getContext();
        $this->page = [
            'template' => $template,
            'context' => $context,
            'description' => null,
            'diagnostics' => [],
            'features' => [],
        ];

        // Each preparation is guarded on its own: a failure in one (bad data
        // in an article, for example) does not stop the others.
        switch ($template) {
            case 'frontend/pages/article.tpl':
                $this->guard('prepare article', function () use ($templateMgr) {
                    $this->prepareArticle($templateMgr);
                });
                break;
            case 'frontend/pages/issue.tpl':
                $this->guard('prepare issue', function () use ($templateMgr) {
                    $this->prepareIssue($templateMgr, false);
                });
                break;
            case 'frontend/pages/indexJournal.tpl':
                $this->guard('prepare current issue', function () use ($templateMgr) {
                    $this->prepareIssue($templateMgr, true);
                });
                if ($this->featureEnabled('categories')) {
                    $this->guard('prepare category cards', function () use ($templateMgr) {
                        $this->prepareCategories($templateMgr);
                    });
                }
                break;
            case 'frontend/pages/issueArchive.tpl':
                $this->guard('prepare archive cards', function () use ($templateMgr) {
                    $this->prepareArchive($templateMgr);
                });
                break;
        }
        if ($this->featureEnabled('openGraph')) {
            $this->guard('prepare Open Graph', function () use ($templateMgr, $template) {
                $this->prepareOpenGraph($templateMgr, $template);
            });
        }
        $this->guard('prepare meta description', function () use ($context) {
            if ($this->page['description'] === null && $context) {
                $this->page['description'] = trim($context->getLocalizedName() . '. ' . MsdHtml::excerpt($context->getLocalizedData('description'), 400), ' .');
            }
        });

        // Load the bold heading font early (titles, menu): it is needed for
        // the first view of every page, and preloading it shortens the time
        // until the text is shown in its final font.
        $this->guard('font preload', function () use ($templateMgr, $request) {
            $templateMgr->addHeader(
                'manuscriptDaffaFontPreload',
                '<link rel="preload" href="' . MsdHtml::e($request->getBaseUrl() . '/' . $this->getPluginPath() . '/' . self::PRELOAD_FONT)
                    . '" as="font" type="font/woff2" crossorigin>',
                ['contexts' => 'frontend']
            );
        });

        // Smarty stores output filters by name and gives every closure the
        // same name ("closure"), so an unnamed filter would replace, or be
        // replaced by, another plugin's (e.g. Publication Facts Label on the
        // article page). Register under our own name.
        $templateMgr->registerFilter('output', [$this, 'filterOutput'], 'manuscriptDaffa');

        return false;
    }

    /**
     * JSON answer of the back end file size lookup.
     */
    protected function fileSizeResponse(): string
    {
        $request = Application::get()->getRequest();
        $sizes = null;
        if (!$this->featureEnabled('adminFileSizes')) {
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=utf-8');
                header('Cache-Control: no-store, private');
            }
            return json_encode(['sizes' => (object) []]);
        }
        try {
            $sizes = MsdFileSizes::forSubmission((int) $request->getUserVar('submissionId'));
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
                'country' => $code !== '' ? MsdCountries::name($code) : '',
            ];
        }
        $this->page['authors'] = $authors;
        $this->page['doi'] = $this->doiOf($publication);

        $abstract = $publication->getLocalizedData('abstract');
        if (trim(strip_tags((string) $abstract)) !== '') {
            $this->page['description'] = MsdHtml::excerpt($abstract);
        } else {
            $this->page['description'] = MsdHtml::excerpt($publication->getLocalizedFullTitle());
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
            if ($this->featureEnabled('sortByPages')) {
                $publishedSubmissions = MsdIssueStats::sortByPages($publishedSubmissions);
                $templateMgr->assign('publishedSubmissions', $publishedSubmissions);
            }

            $order = [];
            foreach ($publishedSubmissions as $section) {
                foreach ($section['articles'] ?? [] as $article) {
                    $this->submissions[(int) $article->getId()] = $article;
                    $order[] = (int) $article->getId();
                }
            }
            if ($isHome && count($order) > self::HOME_ARTICLE_LIMIT && $this->featureEnabled('homeFold')) {
                $this->page['hiddenArticles'] = array_fill_keys(array_slice($order, self::HOME_ARTICLE_LIMIT), true);
                $this->page['issueUrl'] = $this->url('issue', 'view', [$issue->getBestIssueId()]);
            }
        }

        if ($issue->getPublished() && $this->featureEnabled('coverage')) {
            $issueId = (int) $issue->getId();
            $stamps = MsdIssueStats::stamps((int) $context->getId(), [$issueId]);
            $stats = MsdIssueStats::get((int) $context->getId(), $issueId, $this->getHomeCountry(), $stamps[$issueId] ?? '', $this->refreshRequested());
            $this->page['coverage'] = $stats;
            $this->page['diagnostics'][] = 'countries taken from the affiliation: ' . $stats['guessed'];
        }

        if (!$isHome) {
            $parts = array_filter([
                $issue->getIssueIdentification(),
                MsdHtml::excerpt($issue->getLocalizedDescription(), 400),
            ]);
            $this->page['description'] = MsdHtml::excerpt(implode('. ', $parts));
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
        $categoryDao = DAORegistry::getDAO('CategoryDAO'); /* @var $categoryDao CategoryDAO */
        foreach ($categoryDao->getByContextId($context->getId())->toArray() as $category) {
            $categories[(int) $category->getId()] = $category;
        }
        if (!$categories) {
            $this->page['diagnostics'][] = 'categories with articles in current issue: 0 (the journal has no categories)';
            return;
        }

        $cards = [];
        foreach ($this->submissions as $submission) {
            $publication = $submission->getCurrentPublication();
            foreach ((array) (($publication ? $publication->getData('categoryIds') : null) ?? []) as $categoryId) {
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
        if (!$this->featureEnabled('archiveCards')) {
            return;
        }
        $home = $this->getHomeCountry();
        $issueIds = [];
        foreach ($issues as $issue) {
            $issueIds[] = (int) $issue->getId();
        }
        $stamps = MsdIssueStats::stamps((int) $context->getId(), $issueIds);
        $refresh = $this->refreshRequested();
        $stats = [];
        foreach ($issueIds as $issueId) {
            $stats[] = MsdIssueStats::get((int) $context->getId(), $issueId, $home, $stamps[$issueId] ?? '', $refresh);
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
        // Output filters also run for every fragment rendered with fetch()
        // while the page is displayed (sidebar blocks, for example). Only the
        // displayed page template is changed, even when a block contains a
        // complete HTML document of its own.
        $template = $this->page['template'] ?? '';
        $resource = is_object($smartyTemplate) ? (string) ($smartyTemplate->template_resource ?? '') : '';
        if ($template === '' || ($resource !== '' && !str_ends_with($resource, $template))) {
            return $html;
        }
        if (!str_contains($html, '</body>')) {
            return $html;
        }
        if (!empty($this->page['done'])) {
            return $html;
        }
        $this->page['done'] = true;

        // One step per feature, each guarded on its own: when a step fails
        // the page keeps the result of the other steps, and the error goes
        // to the PHP error log and the diagnostic comment.
        // [report name, feature switch, step]
        $steps = [];
        if ($template === 'frontend/pages/article.tpl') {
            $steps[] = ['author countries', 'authorCountry', [$this, 'filterArticleAuthors']];
            $steps[] = ['Dimensions badge', 'dimensions', [$this, 'filterDimensions']];
        }
        if (in_array($template, ['frontend/pages/issue.tpl', 'frontend/pages/indexJournal.tpl'])) {
            $steps[] = ['issue coverage', 'coverage', [$this, 'filterCoverage']];
        }
        if ($template === 'frontend/pages/indexJournal.tpl') {
            $steps[] = ['homepage fold', 'homeFold', [$this, 'filterHomeFold']];
            $steps[] = ['category cards', 'categories', [$this, 'filterCategories']];
        }
        if ($template === 'frontend/pages/issueArchive.tpl') {
            // Lazy covers belong to "image loading"; the step checks both switches
            $steps[] = ['archive cards', null, [$this, 'filterArchive']];
        }
        if (str_contains($html, '<div class="obj_article_summary">')) {
            $steps[] = ['article action rows', 'actionRows', [$this, 'filterArticleList']];
        }
        $steps[] = ['sidebar menu', null, [$this, 'filterSidebarMenu']];
        $steps[] = ['image loading', 'imageLoading', [$this, 'filterImages']];
        $steps[] = ['meta description', 'metaDescription', [$this, 'filterMetaDescription']];
        $steps[] = ['Open Graph', 'openGraph', [$this, 'filterOpenGraph']];

        foreach ($steps as [$name, $feature, $step]) {
            if ($feature !== null && !$this->featureEnabled($feature)) {
                $this->report($name, 'disabled in the theme settings');
                continue;
            }
            $html = $this->runStep($name, $step, $html);
        }

        return $this->runStep('diagnostics', [$this, 'filterDiagnostics'], $html);
    }

    /**
     * Run one output step; on failure keep the HTML as it was.
     */
    protected function runStep(string $name, callable $step, string $html): string
    {
        $result = $this->guard($name, function () use ($step, $html) {
            return $step($html);
        });
        return is_string($result) ? $result : $html;
    }

    /**
     * Run a callback, log and report a failure instead of throwing.
     *
     * @return mixed The callback's result, or null when it failed
     */
    protected function guard(string $name, callable $callback)
    {
        try {
            return $callback();
        } catch (Throwable $e) {
            $where = basename($e->getFile()) . ':' . $e->getLine();
            error_log('manuscriptDaffa: ' . $name . ' failed on ' . ($this->page['template'] ?? '?') . ': ' . $e->getMessage() . ' (' . $where . ')');
            $this->report($name, 'ERROR ' . $e->getMessage() . ' (' . $where . ')');
            return null;
        }
    }

    /**
     * Record what a feature did on this page, for the diagnostic comment.
     */
    protected function report(string $feature, string $status): void
    {
        $this->page['features'][$feature] = $status;
    }

    /**
     * Article page: add the country after the affiliation of each author.
     */
    protected function filterArticleAuthors(string $html): string
    {
        $authors = $this->page['authors'] ?? [];
        if (!$authors) {
            $this->report('author countries', 'skipped: the publication has no authors');
            return $html;
        }
        $start = strpos($html, '<section class="item authors">');
        if ($start === false) {
            $this->report('author countries', 'skipped: author section not found (template changed by another theme or plugin?)');
            return $html;
        }
        // Other plugins may add attributes (PFL: <ul id="author-list" class="authors">)
        if (!preg_match('~<ul\b[^>]*\bclass="authors"[^>]*>~', $html, $um, PREG_OFFSET_CAPTURE, $start)) {
            $this->report('author countries', 'skipped: author list not found');
            return $html;
        }
        $ulStart = $um[0][1];
        $ulEnd = MsdHtml::elementEnd($html, $ulStart, 'ul');
        if ($ulEnd === null) {
            $this->report('author countries', 'skipped: author list not closed');
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
        $added = 0;
        $noCountry = 0;
        $unmatched = 0;
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
                $unmatched++;
                continue;
            }
            $used[$author] = true;
            $country = $authors[$author]['country'];
            if ($country === '') {
                $noCountry++;
                continue;
            }
            $added++;

            $affStart = strpos($item, '<span class="affiliation">');
            if ($affStart !== false && ($affEnd = MsdHtml::elementEnd($item, $affStart, 'span')) !== null) {
                $closing = $affEnd - strlen('</span>');
                // OJS 3.3 prints the affiliation as text, then an optional ROR
                // link, with line breaks (rendered as spaces) in between. Put
                // the country right after the text, before the ROR link, so
                // the comma follows the last word ("Mashiro, Indonesia").
                $inner = substr($item, $affStart, $closing - $affStart);
                $link = strpos($inner, '<a ');
                $text = $link !== false ? substr($inner, 0, $link) : $inner;
                $at = $affStart + strlen(rtrim($text));
                $items[$k] = substr($item, 0, $at)
                    . '<span class="msd-country">' . MsdHtml::e(__('common.commaListSeparator') ?: ', ') . MsdHtml::e($country) . '</span>'
                    . substr($item, $at);
            } elseif (preg_match('~<span class="name">.*?</span>~s', $item, $m, PREG_OFFSET_CAPTURE)) {
                $pos = $m[0][1] + strlen($m[0][0]);
                $items[$k] = substr($item, 0, $pos)
                    . '<span class="affiliation msd-country-only">' . MsdHtml::e($country) . '</span>'
                    . substr($item, $pos);
            }
        }

        $this->report('author countries', sprintf(
            'added to %d of %d authors (%d without Country, %d not matched by name)',
            $added,
            count($authors),
            $noCountry,
            $unmatched
        ));

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
        if (!$doi) {
            $this->report('Dimensions badge', 'skipped: the article has no DOI');
            return $html;
        }
        if (!str_contains($html, '<section class="item doi">')) {
            $this->report('Dimensions badge', 'skipped: DOI section not found');
            return $html;
        }
        // js/manuscriptDaffa.js loads badge.dimensions.ai only when the badge
        // comes near the screen, so the third-party script does not slow
        // down the first view of the article page.
        $badge = '<section class="item msd-dimensions" data-msd-dimensions="' . MsdHtml::e(self::DIMENSIONS_SCRIPT) . '">'
            . '<span class="__dimensions_badge_embed__" data-doi="' . MsdHtml::e($doi) . '" data-style="small_rectangle" data-hide-zero-citations="false"></span>'
            . '</section>';
        $this->report('Dimensions badge', 'added for DOI ' . $doi . ' (script loads when the badge scrolls into view)');
        return MsdHtml::insertBefore($html, '<section class="item doi">', $badge);
    }

    /**
     * Issue page and homepage: "Issue Coverage" box next to the cover.
     */
    protected function filterCoverage(string $html): string
    {
        $stats = $this->page['coverage'] ?? null;
        if (!$stats || !$stats['articles']) {
            $this->report('issue coverage', 'skipped: no published articles (or the issue is not published)');
            return $html;
        }
        $tocStart = strpos($html, '<div class="obj_issue_toc">');
        if ($tocStart === false) {
            $this->report('issue coverage', 'skipped: issue table of contents not found');
            return $html;
        }
        $headingStart = strpos($html, '<div class="heading">', $tocStart);
        if ($headingStart === false) {
            $this->report('issue coverage', 'skipped: issue heading not found');
            return $html;
        }
        $insertAt = $headingStart + strlen('<div class="heading">');
        // OJS 3.3: the cover is a link, <a class="cover" href="…"><img></a>
        if (preg_match('~\G\s*<a class="cover"~', $html, $m, 0, $insertAt)) {
            $coverStart = strpos($html, '<a class="cover"', $insertAt);
            $coverEnd = MsdHtml::elementEnd($html, $coverStart, 'a');
            if ($coverEnd !== null) {
                $insertAt = $coverEnd;
            }
        }
        $this->report('issue coverage', sprintf(
            'added (%d articles, %s pages, %d authors, %d countries)',
            $stats['articles'],
            $stats['pages'] ?? '-',
            $stats['authors'],
            count($stats['countries'])
        ));
        return substr($html, 0, $insertAt) . $this->coverageBox($stats) . substr($html, $insertAt);
    }

    protected function coverageBox(array $stats): string
    {
        $out = '<div class="msd-coverage">';
        $out .= '<div class="msd-coverage-title">' . MsdHtml::e(__('plugins.themes.manuscriptDaffa.coverage.title', [
            'authors' => $stats['authors'],
            'countries' => count($stats['countries']),
        ])) . '</div>';
        $out .= '<ul class="msd-coverage-stats">';
        $out .= '<li>' . MsdHtml::e(__('plugins.themes.manuscriptDaffa.coverage.articles', ['count' => $stats['articles']])) . '</li>';
        if ($stats['pages'] !== null) {
            $out .= '<li>' . MsdHtml::e(__('plugins.themes.manuscriptDaffa.coverage.pages', ['count' => $stats['pages']])) . '</li>';
        }
        if ($stats['foreignPct'] !== null) {
            $out .= '<li>' . MsdHtml::e(__('plugins.themes.manuscriptDaffa.coverage.foreign', [
                'count' => $stats['foreign'],
                'percent' => $stats['foreignPct'],
            ])) . '</li>';
        }
        $out .= '</ul>';
        if ($stats['countries']) {
            $out .= '<ul class="msd-coverage-countries">';
            foreach ($stats['countries'] as $code => $count) {
                $name = MsdCountries::name($code);
                $out .= '<li>' . $this->flag($code, $name)
                    . '<span class="msd-country-name">' . MsdHtml::e($name) . '</span> '
                    . '<span class="msd-country-count">' . MsdHtml::e(__('plugins.themes.manuscriptDaffa.coverage.authorCount', ['count' => $count])) . '</span></li>';
            }
            $out .= '</ul>';
        }
        return $out . '</div>';
    }

    protected function flag(string $code, string $name): string
    {
        $request = Application::get()->getRequest();
        $url = MsdCountries::flagUrl($code, $request->getBaseUrl() . '/' . $this->getPluginPath());
        if (!$url) {
            return '';
        }
        return '<img class="msd-flag" src="' . MsdHtml::e($url) . '" alt="" title="' . MsdHtml::e($name) . '" width="20" height="15" loading="lazy" decoding="async">';
    }

    /**
     * Homepage: hide the articles after HOME_ARTICLE_LIMIT and add the
     * "Read more" button.
     */
    protected function filterHomeFold(string $html): string
    {
        $hidden = $this->page['hiddenArticles'] ?? [];
        if (!$hidden) {
            $this->report('homepage fold', 'skipped: ' . self::HOME_ARTICLE_LIMIT . ' articles or fewer');
            return $html;
        }
        $start = strpos($html, '<div class="sections">');
        $endMarker = '</div><!-- .sections -->';
        if ($start === false || ($end = strpos($html, $endMarker, $start)) === false) {
            $this->report('homepage fold', 'skipped: article sections not found');
            return $html;
        }
        $sections = substr($html, $start, $end - $start);

        // Hide the list items
        $sections = preg_replace_callback(
            '~<li>(\s*<div class="obj_article_summary">.*?\bid="article-(\d+)")~s',
            function ($m) use ($hidden) {
                return isset($hidden[(int) $m[2]]) ? '<li class="msd-more" hidden>' . $m[1] : $m[0];
            },
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

        $button = '<div class="msd-readmore-wrap"><a class="msd-readmore" href="' . MsdHtml::e($this->page['issueUrl'] ?? '#') . '"'
            . ' data-label-more="' . MsdHtml::e(__('plugins.themes.manuscriptDaffa.home.readMore')) . '"'
            . ' data-label-less="' . MsdHtml::e(__('plugins.themes.manuscriptDaffa.home.showLess')) . '"'
            . ' aria-expanded="false">' . MsdHtml::e(__('plugins.themes.manuscriptDaffa.home.readMore')) . '</a></div>';

        $this->report('homepage fold', count($hidden) . ' articles behind "Read more"');
        return substr($html, 0, $start) . $sections . $endMarker . $button . substr($html, $end + strlen($endMarker));
    }

    /**
     * Homepage: category cards under the About section.
     */
    protected function filterCategories(string $html): string
    {
        $cards = $this->page['categories'] ?? [];
        if (!$cards) {
            $this->report('category cards', 'skipped: no category with articles in the current issue');
            return $html;
        }

        $out = '<section class="msd-categories"><h2>' . MsdHtml::e(__('plugins.themes.manuscriptDaffa.categories.title')) . '</h2><div class="msd-category-grid">';
        foreach ($cards as $card) {
            $count = count($card['articles']);
            $out .= '<details class="msd-category"><summary>'
                . '<span class="msd-category-name">' . MsdHtml::e($card['category']->getLocalizedTitle()) . '</span>'
                . '<span class="msd-category-count">' . MsdHtml::e(__('plugins.themes.manuscriptDaffa.categories.articles', ['count' => $count])) . '</span>'
                . '</summary><ul>';
            foreach (array_slice($card['articles'], 0, self::CATEGORY_ARTICLE_LIMIT) as $article) {
                $out .= '<li><a href="' . MsdHtml::e($article['url']) . '">' . PKPString::stripUnsafeHtml($article['title']) . '</a></li>';
            }
            $out .= '</ul>';
            if ($count > self::CATEGORY_ARTICLE_LIMIT) {
                $out .= '<a class="msd-category-all" href="' . MsdHtml::e($this->url('catalog', 'category', [$card['category']->getPath()])) . '">'
                    . MsdHtml::e(__('plugins.themes.manuscriptDaffa.categories.viewAll')) . '</a>';
            }
            $out .= '</details>';
        }
        $out .= '</div></section>';

        $pageStart = strpos($html, '<div class="page_index_journal">');
        if ($pageStart === false) {
            $this->report('category cards', 'skipped: homepage content not found');
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
            $this->report('category cards', 'skipped: no place to insert them');
            return $html;
        }
        $this->report('category cards', count($cards) . ' cards added');

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
            $this->report('archive cards', 'skipped: no issues on the page');
            return $html;
        }

        // Covers: lazy loading except the first one
        for ($i = 2; $i <= $count && $this->featureEnabled('imageLoading'); $i++) {
            $parts[$i] = preg_replace_callback(
                '~^(\s*<a class="cover"[^>]*>\s*)(<img\b[^>]*>)~',
                function ($m) {
                    return $m[1] . MsdHtml::addAttribute($m[2], ['img'], 'loading', 'lazy');
                },
                $parts[$i]
            );
        }

        $stats = $this->page['archive'] ?? null;
        $hasOtherCards = stripos($html, 'author-origins') !== false || stripos($html, 'authorOrigins') !== false;
        if (!$this->featureEnabled('archiveCards')) {
            $this->report('archive cards', 'disabled in the theme settings');
        } elseif ($hasOtherCards) {
            $this->report('archive cards', 'skipped: cards of the Author Origins plugin are on the page');
        } elseif (!$stats) {
            $this->report('archive cards', 'skipped: no statistics (see the lines above)');
        } elseif (count($stats) !== $count) {
            $this->report('archive cards', sprintf('skipped: %d issue blocks on the page but %d issues (template changed?)', $count, count($stats)));
        } else {
            $added = 0;
            for ($i = 1; $i <= $count; $i++) {
                $pos = strpos($parts[$i], '</h2>');
                if ($pos === false) {
                    continue;
                }
                $pos += strlen('</h2>');
                $parts[$i] = substr($parts[$i], 0, $pos) . $this->archiveCard($stats[$i - 1]) . substr($parts[$i], $pos);
                $added++;
            }
            $this->report('archive cards', sprintf('added to %d of %d issues', $added, $count));
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
            . '<span class="msd-stat-label">' . MsdHtml::e(__('plugins.themes.manuscriptDaffa.archive.authors')) . '</span>';
        if ($stats['foreignPct'] !== null) {
            $out .= '<span class="msd-stat-sub">' . MsdHtml::e(__('plugins.themes.manuscriptDaffa.archive.foreign', ['percent' => $stats['foreignPct']])) . '</span>';
        }
        $out .= '</div>';

        $out .= '<div class="msd-stat"><span class="msd-stat-num">' . count($stats['countries']) . '</span>'
            . '<span class="msd-stat-label">' . MsdHtml::e(__('plugins.themes.manuscriptDaffa.archive.countries')) . '</span>';
        if ($stats['countries']) {
            $out .= '<span class="msd-stat-sub msd-flags">';
            $shown = 0;
            foreach ($stats['countries'] as $code => $n) {
                if ($shown >= self::ARCHIVE_FLAG_LIMIT) {
                    break;
                }
                $out .= $this->flag($code, MsdCountries::name($code));
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
            . '<span class="msd-stat-label">' . MsdHtml::e(__('plugins.themes.manuscriptDaffa.archive.articles')) . '</span>';
        if ($stats['pages'] !== null) {
            $out .= '<span class="msd-stat-sub">' . MsdHtml::e(__('plugins.themes.manuscriptDaffa.archive.pages', ['count' => $stats['pages']])) . '</span>';
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
        $rows = 0;
        $pdfMoved = 0;
        $failed = [];
        for ($i = 1; $i < count($parts); $i++) {
            $chunk = $parts[$i];
            if (!preg_match('~\bid="article-(\d+)"~', $chunk, $m)) {
                continue;
            }
            $id = (int) $m[1];
            // One article with bad data must not cost the others their row
            $data = $this->guard('article action row ' . $id, function () use ($id) {
                return $this->listData($id);
            });
            if (!$data) {
                $failed[] = $id;
                continue;
            }

            // Take the PDF galley button of this article out of the list.
            // OJS 3.3 galley links have no id: look only inside this article
            // summary (up to its closing tag).
            $pdfUrl = null;
            $summaryEnd = MsdHtml::elementEnd('<div class="obj_article_summary">' . $chunk, 0, 'div');
            $summaryLength = $summaryEnd !== null ? $summaryEnd - strlen('<div class="obj_article_summary">') : strlen($chunk);
            $summary = substr($chunk, 0, $summaryLength);
            $galleyPattern = '~<li>\s*<a class="obj_galley_link pdf[^"]*" href="([^"]+)"[^>]*>.*?</a>\s*</li>~s';
            if (preg_match($galleyPattern, $summary, $g)) {
                $pdfUrl = html_entity_decode($g[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $summary = preg_replace($galleyPattern, '', $summary, 1);
                $summary = preg_replace('~<ul class="galleys_links">\s*</ul>~', '', $summary, 1);
                $chunk = $summary . substr($chunk, $summaryLength);
                $pdfMoved++;
            }
            $pdfUrl = $pdfUrl ?: $data['pdf'];

            $row = $this->actionRow($id, $data, $pdfUrl);

            // After the meta block (authors, pages), otherwise after the title
            $at = null;
            $metaStart = strpos($chunk, '<div class="meta">');
            if ($metaStart !== false) {
                $at = MsdHtml::elementEnd($chunk, $metaStart, 'div');
            }
            if ($at === null && preg_match('~</h[1-6]>~', $chunk, $h, PREG_OFFSET_CAPTURE)) {
                $at = $h[0][1] + strlen($h[0][0]);
            }
            if ($at === null) {
                $failed[] = $id;
                continue;
            }
            $parts[$i] = substr($chunk, 0, $at) . $row . substr($chunk, $at);
            $rows++;
        }
        $this->report('article action rows', sprintf(
            'added to %d of %d articles, PDF button moved for %d%s',
            $rows,
            count($parts) - 1,
            $pdfMoved,
            $failed ? ' (not added: article ' . implode(', ', $failed) . ')' : ''
        ));
        return implode('<div class="obj_article_summary">', $parts);
    }

    protected function actionRow(int $id, array $data, ?string $pdfUrl): string
    {
        $links = [];
        $abstract = '';
        if ($data['abstract'] !== '') {
            $links[] = '<a class="msd-act msd-act-abstract" href="' . MsdHtml::e($data['url']) . '" aria-expanded="false" aria-controls="msd-abstract-' . $id . '"'
                . ' data-label-show="' . MsdHtml::e(__('plugins.themes.manuscriptDaffa.list.showAbstract')) . '"'
                . ' data-label-hide="' . MsdHtml::e(__('plugins.themes.manuscriptDaffa.list.hideAbstract')) . '">'
                . MsdHtml::e(__('plugins.themes.manuscriptDaffa.list.showAbstract')) . '</a>';
            $abstract = '<div class="msd-abstract" id="msd-abstract-' . $id . '" hidden>' . $data['abstract'] . '</div>';
        }
        if ($pdfUrl) {
            $links[] = '<a class="msd-act msd-act-pdf" href="' . MsdHtml::e($pdfUrl) . '">' . MsdHtml::e(__('plugins.themes.manuscriptDaffa.list.downloadPdf')) . '</a>';
        }
        if ($data['doi']) {
            $links[] = '<a class="msd-act msd-act-doi" href="https://doi.org/' . MsdHtml::e($this->doiPath($data['doi'])) . '" target="_blank" rel="noopener">DOI: ' . MsdHtml::e($data['doi']) . '</a>';
        }
        if ($data['title'] !== '') {
            $links[] = '<a class="msd-act msd-act-scholar" href="https://scholar.google.com/scholar?q=' . rawurlencode('"' . $data['title'] . '"') . '" target="_blank" rel="noopener">'
                . MsdHtml::e(__('plugins.themes.manuscriptDaffa.list.scholar')) . '</a>';
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
            $submission = Services::get('submission')->get($id);
            if (!$submission || (int) $submission->getData('status') !== STATUS_PUBLISHED) {
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
            $contextPath = $other ? $other->getPath() : null;
        }

        $pdf = null;
        foreach ($publication->getData('galleys') ?? [] as $galley) {
            if (!$galley->getRemoteUrl() && $galley->isPdfGalley()) {
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
     * Sidebar: lists of links in the blocks become a menu with icons
     * (theme option "Sidebar menu style", see classes/MsdSideMenu.inc.php).
     */
    protected function filterSidebarMenu(string $html): string
    {
        $style = (string) $this->getOption('sidebarMenu');
        if (!in_array($style, self::SIDEBAR_MENU_STYLES, true)) {
            $style = 'soft';
        }
        if ($style === 'off') {
            $this->report('sidebar menu', 'off in the theme settings');
            return $html;
        }
        $menu = new MsdSideMenu($style, (string) Application::get()->getRequest()->getRequestUrl());
        $html = MsdHtml::region($html, '<div class="pkp_structure_sidebar', '</div><!-- pkp_sidebar.left -->', [$menu, 'apply']);
        $this->report('sidebar menu', $menu->menus
            ? sprintf('%d menus with %d links (%s)', $menu->menus, $menu->links, $style)
            : 'no list of links found in the sidebar');
        return $html;
    }

    /**
     * Images: header logo first, lazy sidebar and footer media (not counters).
     */
    protected function filterImages(string $html): string
    {
        $html = MsdHtml::region($html, '<div class="pkp_site_name">', '</div>', function ($region) {
            return preg_replace_callback('~<img\b[^>]*>~i', function ($m) {
                return MsdHtml::addAttribute($m[0], ['img'], 'fetchpriority', 'high');
            }, $region, 1);
        });

        $skip = function (string $tag) {
            return (bool) preg_match(self::COUNTER_PATTERN, $tag);
        };
        $lazy = function (string $region) use ($skip) {
            return MsdHtml::addAttribute($region, ['img', 'iframe'], 'loading', 'lazy', $skip);
        };

        $before = substr_count($html, ' loading="lazy"');
        $html = MsdHtml::region($html, '<div class="pkp_structure_sidebar', '</div><!-- pkp_sidebar.left -->', $lazy);
        $html = MsdHtml::region($html, '<div class="pkp_structure_footer_wrapper"', '</div><!-- pkp_structure_footer_wrapper -->', $lazy);

        $this->report('image loading', sprintf(
            'header logo %s, %d sidebar/footer images lazy-loaded',
            str_contains($html, 'fetchpriority="high"') ? 'loaded first' : 'not found',
            substr_count($html, ' loading="lazy"') - $before
        ));
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
            $this->report('meta description', 'kept the existing one');
            return $html;
        }
        $description = MsdHtml::excerpt($this->page['description'] ?? '');
        if ($description === '') {
            $this->report('meta description', 'skipped: no text');
            return $html;
        }
        $this->report('meta description', 'added (' . mb_strlen($description, 'UTF-8') . ' characters)');
        return substr($html, 0, $headEnd) . '<meta name="description" content="' . MsdHtml::e($description) . '">' . "\n" . substr($html, $headEnd);
    }

    /**
     * Data for the Open Graph tags (link previews in WhatsApp, Facebook,
     * Telegram, X and others): title, type, canonical URL and an image
     * (article cover, issue cover, journal thumbnail or header logo).
     */
    protected function prepareOpenGraph(TemplateManager $templateMgr, string $template): void
    {
        $context = $this->page['context'];
        if (!$context) {
            return;
        }
        $og = ['type' => 'website', 'title' => null, 'url' => null, 'image' => null, 'imageAlt' => null, 'published' => null];

        switch ($template) {
            case 'frontend/pages/article.tpl':
                $publication = $templateMgr->getTemplateVars('publication');
                $article = $templateMgr->getTemplateVars('article');
                if ($publication) {
                    $og['type'] = 'article';
                    $og['title'] = $this->plainText($publication->getLocalizedFullTitle());
                    $og['image'] = $publication->getLocalizedCoverImageUrl((int) $context->getId()) ?: null;
                    $cover = $publication->getLocalizedData('coverImage');
                    $og['imageAlt'] = is_array($cover) ? ($cover['altText'] ?? null) : null;
                    $og['published'] = $publication->getData('datePublished') ?: null;
                }
                if ($article) {
                    $og['url'] = $this->articleUrl($article);
                }
                if (!$og['image'] && ($issue = $templateMgr->getTemplateVars('issue'))) {
                    $og['image'] = $issue->getLocalizedCoverImageUrl() ?: null;
                }
                break;
            case 'frontend/pages/issue.tpl':
                if ($issue = $templateMgr->getTemplateVars('issue')) {
                    $og['title'] = $this->plainText($issue->getIssueIdentification()) . ' | ' . $context->getLocalizedName();
                    $og['url'] = $this->url('issue', 'view', [$issue->getBestIssueId()]);
                    $og['image'] = $issue->getLocalizedCoverImageUrl() ?: null;
                }
                break;
            case 'frontend/pages/indexJournal.tpl':
                $og['title'] = $context->getLocalizedName();
                $og['url'] = $this->url('index', 'index');
                break;
        }

        // Fallback image: journal thumbnail, then the header logo
        if (!$og['image']) {
            foreach (['journalThumbnail', 'pageHeaderLogoImage'] as $setting) {
                $file = $context->getLocalizedData($setting);
                if (is_array($file) && !empty($file['uploadName'])) {
                    $og['image'] = Application::get()->getRequest()->getBaseUrl() . '/'
                        . self::publicFilesPath((int) $context->getId()) . '/'
                        . rawurlencode($file['uploadName']);
                    $og['imageAlt'] = $file['altText'] ?? null;
                    break;
                }
            }
        }
        $this->page['og'] = $og;
    }

    /**
     * Open Graph and Twitter card tags, unless the page has Open Graph tags
     * from another plugin already.
     */
    protected function filterOpenGraph(string $html): string
    {
        $headEnd = stripos($html, '</head>');
        if ($headEnd === false) {
            $this->report('Open Graph', 'skipped: no <head>');
            return $html;
        }
        $head = substr($html, 0, $headEnd);
        if (preg_match('~<meta\s[^>]*property\s*=\s*["\']og:~i', $head)) {
            $this->report('Open Graph', 'kept the existing tags (added by another plugin)');
            return $html;
        }

        $request = Application::get()->getRequest();
        $context = $this->page['context'] ?? null;
        $og = $this->page['og'] ?? [];

        $title = $og['title'] ?? null;
        if (!$title && preg_match('~<title[^>]*>(.*?)</title>~is', $head, $m)) {
            $title = $this->plainText($m[1]);
        }
        $description = MsdHtml::excerpt($this->page['description'] ?? '', 200);
        $url = $og['url'] ?? $request->getRequestUrl();
        $image = $og['image'] ?? null;

        $tags = [
            ['property', 'og:type', $og['type'] ?? 'website'],
            ['property', 'og:site_name', $context ? $context->getLocalizedName() : null],
            ['property', 'og:title', $title],
            ['property', 'og:description', $description],
            ['property', 'og:url', $url],
            ['property', 'og:image', $image],
            ['property', 'og:image:alt', $image ? ($og['imageAlt'] ?: $title) : null],
            ['property', 'article:published_time', $og['published'] ?? null],
            ['name', 'twitter:card', $image ? 'summary_large_image' : 'summary'],
            ['name', 'twitter:title', $title],
            ['name', 'twitter:description', $description],
            ['name', 'twitter:image', $image],
        ];
        $out = '';
        foreach ($tags as [$attr, $name, $value]) {
            $value = trim((string) $value);
            if ($value !== '') {
                $out .= '<meta ' . $attr . '="' . $name . '" content="' . MsdHtml::e($value) . '">' . "\n";
            }
        }

        $this->report('Open Graph', 'added (' . ($og['type'] ?? 'website') . ', ' . ($image ? 'image ' . basename(parse_url($image, PHP_URL_PATH) ?: $image) : 'no image') . ')');
        return substr($html, 0, $headEnd) . $out . substr($html, $headEnd);
    }

    /**
     * Public files folder of a journal, e.g. public/journals/1
     */
    protected static function publicFilesPath(int $contextId): string
    {
        import('classes.file.PublicFileManager');
        return (new PublicFileManager())->getContextFilesPath($contextId);
    }

    protected function plainText(?string $html): string
    {
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) $html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    /**
     * A diagnostic comment for journal managers and admins.
     */
    protected function filterDiagnostics(string $html): string
    {
        $request = Application::get()->getRequest();
        $user = $request->getUser();
        $context = $this->page['context'] ?? null;
        if (!$user || !$context) {
            return $html;
        }
        $isManager = $user->hasRole([ROLE_ID_MANAGER, ROLE_ID_SITE_ADMIN], (int) $context->getId())
            || $user->hasRole([ROLE_ID_SITE_ADMIN], CONTEXT_SITE);
        if (!$isManager) {
            return $html;
        }
        $lines = ['page: ' . ($this->page['template'] ?? '?')];
        foreach ($this->page['features'] ?? [] as $feature => $status) {
            $lines[] = $feature . ': ' . $status;
        }
        foreach ($this->page['diagnostics'] ?? [] as $line) {
            $lines[] = $line;
        }
        $lines[] = 'home country: ' . ($this->getHomeCountry() ?: 'not set');
        $lines[] = 'issue statistics: cached up to ' . (MsdIssueStats::TTL / 3600) . ' h, renewed when an article of the issue changes'
            . ($this->refreshRequested() ? '; recomputed now (' . self::REFRESH_PARAM . ')' : '; add ?' . self::REFRESH_PARAM . '=1 to the URL to recompute now');
        $disabled = array_diff(self::FEATURES, array_filter(self::FEATURES, [$this, 'featureEnabled']));
        $lines[] = 'features switched off: ' . ($disabled ? implode(', ', $disabled) : 'none');
        $comment = "\n<!-- manuscriptDaffa " . $this->getVersionString() . "\n  " . implode("\n  ", array_map(
            function ($l) {
                return str_replace('--', '- -', $l);
            },
            $lines
        )) . "\n-->\n";
        return MsdHtml::insertBefore($html, '</body>', $comment);
    }

    /*
     * Helpers
     */

    protected function getVersionString(): string
    {
        static $release = null;
        if ($release === null) {
            $version = @simplexml_load_file($this->getPluginPath() . '/version.xml');
            $release = $version ? (string) $version->release : '';
        }
        return $release;
    }

    /**
     * Asset path with the theme version, so browsers and CDNs (Cloudflare)
     * load the new file after every theme update. OJS only adds its own
     * "?v=<OJS version>" when the path has no query, and that value does
     * not change when the theme is updated.
     */
    protected function versioned(string $path): string
    {
        $release = $this->getVersionString();
        return $release !== '' ? $path . '?v=' . rawurlencode($release) : $path;
    }

    protected function url(string $page, string $op, array $path = [], ?string $contextPath = null): string
    {
        $request = Application::get()->getRequest();
        $context = $request->getContext();
        return $request->getDispatcher()->url(
            $request,
            ROUTE_PAGE,
            $contextPath ?? ($context ? $context->getPath() : null),
            $page,
            $op,
            array_map('strval', $path)
        );
    }

    protected function articlePath($submission): string
    {
        $publication = $submission->getCurrentPublication();
        return (string) (($publication ? $publication->getData('urlPath') : null) ?: $submission->getId());
    }

    protected function articleUrl($submission, ?string $contextPath = null): string
    {
        return $this->url('article', 'view', [$this->articlePath($submission)], $contextPath);
    }

    protected function doiOf($publication): ?string
    {
        try {
            // OJS 3.3: DOIs are stored by the DOI pub-id plugin
            $doi = $publication->getStoredPubId('doi');
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
