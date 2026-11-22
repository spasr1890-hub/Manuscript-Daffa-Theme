<?php

/**
 * @file plugins/themes/manuscriptDaffa/tests/bootstrap.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * Minimal stand-ins for the OJS/PKP classes the theme uses, so the HTML
 * filters and helpers can be tested without an OJS installation. Only what
 * the tests need is implemented. Translations come from locale/en.
 */

namespace Illuminate\Support\Facades {
    class Cache
    {
        public static function remember($key, $ttl, $callback)
        {
            return $callback();
        }

        public static function put($key, $value, $ttl)
        {
        }
    }

    class DB
    {
    }
}

namespace PKP\facades {
    class Locale
    {
        public static array $countries = [
            'ID' => ['Indonesia', 'Indonesia'],
            'MY' => ['Malaysia', 'Malaysia'],
            'US' => ['United States', 'Amerika Serikat'],
            'NL' => ['Netherlands', 'Belanda'],
            'JP' => ['Japan', 'Jepang'],
        ];

        public static function getLocale()
        {
            return 'id';
        }

        public static function getCountries()
        {
            $list = [];
            foreach (self::$countries as $code => [$en, $local]) {
                $list[] = new class ($code, $en, $local) {
                    public function __construct(public $code, public $en, public $local)
                    {
                    }

                    public function getAlpha2()
                    {
                        return $this->code;
                    }

                    public function getLocalName()
                    {
                        return $this->local;
                    }

                    public function getName()
                    {
                        return $this->en;
                    }
                };
            }
            return $list;
        }
    }
}

namespace PKP\plugins {
    class Hook
    {
        public const CONTINUE = false;
        public const ABORT = true;
        public const SEQUENCE_CORE = 0;

        public static function add(...$args)
        {
        }
    }

    class ThemePlugin
    {
        public function getPluginPath()
        {
            return dirname(__DIR__);
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
}

namespace PKP\core {
    class PKPString
    {
        public static function stripUnsafeHtml($html)
        {
            return $html;
        }
    }
}

namespace PKP\file {
    class PublicFileManager
    {
        public function getContextFilesPath($contextId)
        {
            return 'public/journals/' . $contextId;
        }
    }
}

namespace PKP\security {
    class Role
    {
        public const ROLE_ID_MANAGER = 16;
        public const ROLE_ID_SITE_ADMIN = 1;
        public const ROLE_ID_SUB_EDITOR = 17;
        public const ROLE_ID_AUTHOR = 65536;
        public const ROLE_ID_ASSISTANT = 4097;
    }
}

namespace PKP\submissionFile {
    class SubmissionFile
    {
        public const SUBMISSION_FILE_SUBMISSION = 2;
        public const SUBMISSION_FILE_REVIEW_FILE = 4;
        public const SUBMISSION_FILE_REVIEW_ATTACHMENT = 5;
        public const SUBMISSION_FILE_COPYEDIT = 9;
        public const SUBMISSION_FILE_PROOF = 10;
        public const SUBMISSION_FILE_REVIEW_REVISION = 15;
    }
}

namespace APP\submission {
    class Submission
    {
        public const STATUS_PUBLISHED = 3;

        public function __construct(public array $d, public $pub)
        {
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
}

namespace APP\template {
    class TemplateManager
    {
        public function __construct(public array $vars = [])
        {
        }

        public function getTemplateVars($key)
        {
            return $this->vars[$key] ?? null;
        }
    }
}

namespace APP\facades {
    class Repo
    {
        public static array $submissions = [];

        public static function submission()
        {
            return new class () {
                public function get($id)
                {
                    return \APP\facades\Repo::$submissions[$id] ?? null;
                }
            };
        }
    }
}

namespace APP\core {
    class Application
    {
        public const ROUTE_PAGE = 'page';
        public const SITE_CONTEXT_ID = 0;
        public const ASSOC_TYPE_REVIEW_ASSIGNMENT = 517;

        public static function get()
        {
            return new class () {
                public function getRequest()
                {
                    return new class () {
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
                            return new class () {
                                public function url($request, $type, $context, $page, $op, $path = null, $params = null)
                                {
                                    return "https://j.test/index.php/{$context}/{$page}/{$op}" . ($path ? '/' . implode('/', $path) : '');
                                }
                            };
                        }
                    };
                }
            };
        }

        public static function getContextDAO()
        {
            return null;
        }
    }
}

namespace {
    /** Generic data object: getFoo() and getLocalizedFoo() return $d['foo'] */
    class Obj
    {
        public function __construct(public array $d)
        {
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
        public function getDoi()
        {
            return $this->d['doi'] ?? null;
        }

        public function getLocalizedCoverImageUrl($contextId)
        {
            return $this->d['cover'] ?? '';
        }
    }

    class Issue extends Obj
    {
        public function getLocalizedCoverImageUrl()
        {
            return $this->d['cover'] ?? '';
        }

        public function getIssueIdentification()
        {
            return $this->d['identification'] ?? 'Vol. 1 No. 1 (2026)';
        }

        public function getBestIssueId()
        {
            return $this->d['id'] ?? 1;
        }
    }

    /** English translations of the theme, read from locale/en/locale.po */
    function __($key, $params = [])
    {
        static $messages = null;
        if ($messages === null) {
            preg_match_all('~msgid "([^"]+)"\nmsgstr "((?:[^"\\\\]|\\\\.)*)"~', file_get_contents(dirname(__DIR__) . '/locale/en/locale.po'), $m, PREG_SET_ORDER);
            $messages = ['common.commaListSeparator' => ', '];
            foreach ($m as [, $id, $str]) {
                $messages[$id] = stripcslashes($str);
            }
        }
        $text = $messages[$key] ?? $key;
        foreach ($params as $name => $value) {
            $text = str_replace('{$' . $name . '}', (string) $value, $text);
        }
        return $text;
    }

    $root = dirname(__DIR__);
    foreach (['classes/Html.php', 'classes/Countries.php', 'classes/IssueStats.php', 'classes/SideMenu.php', 'ManuscriptDaffaThemePlugin.php'] as $file) {
        require_once $root . '/' . $file;
    }
}
