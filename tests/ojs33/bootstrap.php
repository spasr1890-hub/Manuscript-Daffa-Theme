<?php

/**
 * @file plugins/themes/manuscriptDaffa/tests/ojs33/bootstrap.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * Minimal stand-ins for the OJS 3.3 classes and constants the OJS 3.3 build
 * uses, so its HTML filters can be tested without OJS. PHP 7.3 compatible.
 * Translations come from ojs33/locale/en_US.
 */

namespace Illuminate\Database\Capsule {
    class Manager
    {
    }
}

namespace Sokil\IsoCodes {
    class IsoCodesFactory
    {
        public function getCountries()
        {
            $list = [];
            foreach (['ID' => ['Indonesia', 'Indonesia'], 'MY' => ['Malaysia', 'Malaysia'], 'US' => ['United States', 'Amerika Serikat'], 'NL' => ['Netherlands', 'Belanda']] as $code => $names) {
                $list[] = new TestCountry($code, $names[0], $names[1]);
            }
            return $list;
        }
    }

    class TestCountry
    {
        private $code;
        private $en;
        private $local;

        public function __construct($code, $en, $local)
        {
            $this->code = $code;
            $this->en = $en;
            $this->local = $local;
        }

        public function getAlpha2()
        {
            return $this->code;
        }

        public function getName()
        {
            return $this->en;
        }

        public function getLocalName()
        {
            return $this->local;
        }
    }
}

namespace {
    // PHP 8 string functions: OJS 3.3 provides them through symfony/polyfill-php80
    if (!function_exists('str_contains')) {
        function str_contains($haystack, $needle)
        {
            return $needle === '' || strpos($haystack, $needle) !== false;
        }
        function str_starts_with($haystack, $needle)
        {
            return strncmp($haystack, $needle, strlen($needle)) === 0;
        }
        function str_ends_with($haystack, $needle)
        {
            return $needle === '' || substr($haystack, -strlen($needle)) === $needle;
        }
    }

    define('ROUTE_PAGE', 'page');
    define('CONTEXT_SITE', 0);
    define('HOOK_SEQUENCE_CORE', 0);
    define('ROLE_ID_MANAGER', 16);
    define('ROLE_ID_SITE_ADMIN', 1);
    define('ROLE_ID_SUB_EDITOR', 17);
    define('ROLE_ID_AUTHOR', 65536);
    define('ROLE_ID_ASSISTANT', 4097);
    define('STATUS_PUBLISHED', 3);
    define('ASSOC_TYPE_REVIEW_ASSIGNMENT', 517);
    define('SUBMISSION_FILE_SUBMISSION', 2);
    define('SUBMISSION_FILE_REVIEW_ATTACHMENT', 5);
    define('SUBMISSION_FILE_COPYEDIT', 9);
    define('SUBMISSION_FILE_PROOF', 10);
    define('SUBMISSION_FILE_REVIEW_REVISION', 15);

    function import($class)
    {
    }

    class HookRegistry
    {
        public static function register($name, $callback, $sequence = 256)
        {
        }
    }

    class ThemePlugin
    {
        public function getPluginPath()
        {
            return dirname(__DIR__, 2) . '/ojs33';
        }

        public function isColourDark($colour)
        {
            return true;
        }

        public function getOption($name)
        {
            return null;
        }

        public function getSetting($contextId, $name)
        {
            return null;
        }
    }

    class PluginRegistry
    {
        public static function getPlugins($category)
        {
            return [];
        }
    }

    class PKPString
    {
        public static function stripUnsafeHtml($html)
        {
            return $html;
        }
    }

    class PublicFileManager
    {
        public function getContextFilesPath($contextId)
        {
            return 'public/journals/' . $contextId;
        }
    }

    class TemplateManager
    {
        public $vars;

        public function __construct(array $vars = [])
        {
            $this->vars = $vars;
        }

        public function getTemplateVars($key)
        {
            return $this->vars[$key] ?? null;
        }
    }

    class AppLocale
    {
        public static function getLocale()
        {
            return 'id_ID';
        }
    }

    class Services
    {
        public static $submissions = [];

        public static function get($service)
        {
            return new TestSubmissionService();
        }
    }

    class TestSubmissionService
    {
        public function get($id)
        {
            return Services::$submissions[$id] ?? null;
        }
    }

    class TestRequest
    {
        public function getContext()
        {
            return $GLOBALS['testContext'] ?? null;
        }

        public function getUser()
        {
            return null;
        }

        public function getBaseUrl()
        {
            return 'https://j.test';
        }

        public function getRequestUrl()
        {
            return 'https://j.test/index.php/jt/about';
        }

        public function getUserVar($key)
        {
            return null;
        }

        public function getDispatcher()
        {
            return new TestDispatcher();
        }
    }

    class TestDispatcher
    {
        public function url($request, $type, $context, $page, $op, $path = null, $params = null)
        {
            return "https://j.test/index.php/{$context}/{$page}/{$op}" . ($path ? '/' . implode('/', $path) : '');
        }
    }

    class TestApplication
    {
        public function getRequest()
        {
            return new TestRequest();
        }
    }

    class Application
    {
        public static function get()
        {
            return new TestApplication();
        }

        public static function getContextDAO()
        {
            return null;
        }
    }

    /** Generic data object: getFoo() and getLocalizedFoo() return $d['foo'] */
    class Obj
    {
        public $d;

        public function __construct(array $d)
        {
            $this->d = $d;
        }

        public function getData($key)
        {
            return $this->d[$key] ?? null;
        }

        public function getLocalizedData($key)
        {
            return $this->d[$key] ?? null;
        }

        public function __call($method, $args)
        {
            $key = lcfirst(preg_replace('/^get(Localized)?/', '', $method));
            return $this->d[$key] ?? null;
        }
    }

    class Publication extends Obj
    {
        public function getStoredPubId($type)
        {
            return $this->d['pub-id::' . $type] ?? null;
        }

        public function getLocalizedCoverImageUrl($contextId)
        {
            return $this->d['cover'] ?? '';
        }
    }

    class Submission
    {
        public $d;
        public $pub;

        public function __construct(array $d, $pub)
        {
            $this->d = $d;
            $this->pub = $pub;
        }

        public function getId()
        {
            return $this->d['id'];
        }

        public function getData($key)
        {
            return $this->d[$key] ?? null;
        }

        public function getCurrentPublication()
        {
            return $this->pub;
        }
    }

    /** English translations of the theme, read from ojs33/locale/en_US/locale.po */
    function __($key, $params = [])
    {
        static $messages = null;
        if ($messages === null) {
            preg_match_all('~msgid "([^"]+)"\nmsgstr "((?:[^"\\\\]|\\\\.)*)"~', file_get_contents(dirname(__DIR__, 2) . '/ojs33/locale/en_US/locale.po'), $m, PREG_SET_ORDER);
            $messages = ['common.commaListSeparator' => ', '];
            foreach ($m as $match) {
                $messages[$match[1]] = stripcslashes($match[2]);
            }
        }
        $text = $messages[$key] ?? $key;
        foreach ($params as $name => $value) {
            $text = str_replace('{$' . $name . '}', (string) $value, $text);
        }
        return $text;
    }

    require_once dirname(__DIR__, 2) . '/ojs33/ManuscriptDaffaThemePlugin.inc.php';
}
