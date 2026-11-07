<?php

/**
 * @file plugins/themes/manuscriptDaffa/classes/MsdSideMenu.inc.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class MsdSideMenu
 *
 * @brief Turns lists of links in the sidebar blocks into a menu with an icon
 *  per link (theme option "Sidebar menu style"). Recognised markup:
 *  - <ul>/<ol> whose items each hold a single link;
 *  - three or more paragraphs in a row that each hold a single link;
 *  - three or more links separated by <br>.
 *  The icon is chosen from the link text (English and Indonesian words).
 *  Images, image links (indexing logos, badges) and other text are left
 *  alone.
 *
 *  The class body is the same in the OJS 3.4/3.5 build (SideMenu); the
 *  tests check that the two copies match. Keep it PHP 7.3 compatible.
 */

class MsdSideMenu
{
    /** A link, optionally inside inline formatting tags */
    private const LINK = '(?:<(?:strong|b|em|span|font)\b[^>]*>\s*)*<a\b[^>]*>(?:(?!</a>).)*</a>(?:\s*</(?:strong|b|em|span|font)>)*';

    /** Longest link text that is still a menu item (not an article title) */
    private const MAX_TEXT = 80;

    /**
     * Icon => words that choose it, tried in this order. A word matches at
     * the start of a word of the link text ("guideline" matches
     * "Guidelines"); \b marks a whole word.
     */
    public const ICONS = [
        'home' => ['home', 'beranda', 'halaman utama'],
        'fee' => ['fee', 'apc\b', 'article processing charge', 'charge', 'biaya', 'payment', 'pembayaran', 'price', 'harga', 'donat'],
        'process' => ['process', 'proses', 'alur', 'workflow', 'procedure', 'prosedur'],
        'reviewers' => ['reviewer', 'mitra bestari', 'penelaah', 'reviu'],
        'team' => ['editorial', 'editor', 'redaksi', 'team\b', 'tim\b', 'board', 'dewan', 'pengelola', 'people'],
        'focus' => ['focus', 'scope', 'fokus', 'ruang lingkup', 'lingkup', 'aim\b', 'aims\b'],
        'openAccess' => ['open access', 'akses terbuka'],
        'ai' => ['ai\b', 'artificial', 'kecerdasan', 'generative', 'generatif'],
        'ethics' => ['ethic', 'etika', 'kode etik', 'malpractice', 'code of conduct'],
        'plagiarism' => ['plagia', 'similarity', 'kemiripan', 'turnitin'],
        'template' => ['template', 'templat', 'download', 'unduh'],
        'guide' => ['guideline', 'guide', 'panduan', 'pedoman', 'petunjuk', 'instruction', 'author', 'penulis'],
        'copyright' => ['copyright', 'hak cipta'],
        'license' => ['licen', 'lisensi', 'creative commons'],
        'indexing' => ['index', 'indeks', 'pengindeks', 'abstracting', 'search', 'cari\b', 'pencarian'],
        'contact' => ['contact', 'kontak', 'hubungi', 'e-?mail', 'surel'],
        'submit' => ['submi', 'kirim'],
        'archive' => ['archive', 'arsip', 'back issue'],
        'announcement' => ['announce', 'pengumuman', 'news', 'berita'],
        'history' => ['history', 'sejarah', 'riwayat'],
        'stats' => ['statisti', 'visitor', 'pengunjung', 'metric', 'metrik'],
        'reader' => ['reader', 'pembaca', 'current issue', 'terbitan terkini', 'issue\b', 'terbitan'],
        'library' => ['librar', 'pustakawan', 'perpustakaan'],
        'privacy' => ['privacy', 'privasi'],
        'alert' => ['retract', 'correction', 'pencabutan', 'koreksi', 'errat', 'complaint', 'pengaduan', 'appeal', 'banding'],
        'policy' => ['polic', 'kebijakan'],
        'info' => ['about', 'tentang', 'profil', 'info'],
        'language' => ['english', 'bahasa', 'indonesia', 'español', 'deutsch', 'français', 'العربية'],
    ];

    /** Icon of a link whose text matches no word */
    public const DEFAULT_ICON = 'page';

    /** @var string */
    private $style;

    /** @var string Path and query of the current page, to mark its link */
    private $current;

    /** @var int */
    public $menus = 0;

    /** @var int */
    public $links = 0;

    public function __construct(string $style, string $currentUrl)
    {
        $this->style = $style;
        $this->current = self::normaliseUrl($currentUrl);
    }

    /**
     * Change the sidebar HTML; the counters tell what was changed.
     */
    public function apply(string $html): string
    {
        $html = $this->lists($html);
        $html = $this->paragraphRuns($html);
        return $this->breakRuns($html);
    }

    /**
     * Icon name for a link text.
     */
    public static function iconFor(string $text): string
    {
        $text = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $text)), 'UTF-8');
        foreach (self::ICONS as $icon => $words) {
            foreach ($words as $word) {
                if (preg_match('~(?<![\p{L}\p{N}])' . $word . '~u', $text)) {
                    return $icon;
                }
            }
        }
        return self::DEFAULT_ICON;
    }

    /**
     * <ul>/<ol> lists whose items each hold one link.
     */
    private function lists(string $html): string
    {
        $offset = 0;
        while (preg_match('~<(ul|ol)\b([^>]*)>~i', $html, $m, PREG_OFFSET_CAPTURE, $offset)) {
            $tag = strtolower($m[1][0]);
            $start = $m[0][1];
            $openEnd = $start + strlen($m[0][0]);
            $end = self::elementEnd($html, $start, $tag);
            if ($end === null) {
                break;
            }
            $close = strrpos(substr($html, 0, $end), '<');
            $inner = substr($html, $openEnd, $close - $openEnd);
            $menu = $this->listMenu($m[2][0], $inner, $tag);
            if ($menu === null) {
                $offset = $openEnd;
                continue;
            }
            $html = substr($html, 0, $start) . $menu . substr($html, $end);
            $offset = $start + strlen($menu);
        }
        return $html;
    }

    private function listMenu(string $attributes, string $inner, string $tag): ?string
    {
        if (preg_match('~<(?:ul|ol)\b~i', $inner) || str_contains($attributes, 'msd_menu')) {
            return null;
        }
        if (!preg_match_all('~<li\b([^>]*)>(.*?)</li>~is', $inner, $items, PREG_SET_ORDER)) {
            return null;
        }
        if (count($items) < 2 || trim(str_replace(array_column($items, 0), '', $inner)) !== '') {
            return null;
        }
        $html = '';
        foreach ($items as $item) {
            if (!preg_match('~^\s*(' . self::LINK . ')\s*(?:<br\s*/?>\s*)?$~is', $item[2], $link)) {
                return null;
            }
            $entry = $this->item($link[1], $item[1]);
            if ($entry === null) {
                return null;
            }
            $html .= $entry;
        }
        $this->menus++;
        $this->links += count($items);
        return '<' . $tag . $this->withClass($attributes, $this->menuClass()) . '>' . $html . '</' . $tag . '>';
    }

    /**
     * Three or more paragraphs in a row, each holding one link.
     */
    private function paragraphRuns(string $html): string
    {
        $paragraph = '<p\b[^>]*>(?:\s|&nbsp;)*' . self::LINK . '(?:\s|&nbsp;|<br\s*/?>)*</p>';
        $empty = '<p\b[^>]*>(?:\s|&nbsp;|<br\s*/?>)*</p>';
        return preg_replace_callback('~(?:' . $paragraph . '(?:\s|' . $empty . ')*){3,}~is', function ($m) {
            return $this->runMenu($m[0], 3) ?? $m[0];
        }, $html);
    }

    /**
     * Three or more links separated by <br> (in a paragraph or not).
     */
    private function breakRuns(string $html): string
    {
        $br = '\s*<br\s*/?>(?:\s|&nbsp;)*';
        $pattern = '~(<p\b[^>]*>\s*)?((?:' . self::LINK . $br . '){2,}' . self::LINK . ')(?:' . $br . ')?(\s*</p>)?~is';
        return preg_replace_callback($pattern, function ($m) {
            $menu = $this->runMenu($m[2], 3);
            if ($menu === null) {
                return $m[0];
            }
            $open = $m[1] ?? '';
            $close = $m[3] ?? '';
            if ($open !== '' && $close !== '') {
                return $menu; // the paragraph held only the links
            }
            return ($open !== '' ? $open . '</p>' : '') . $menu . ($close !== '' ? '<p>' . $close : '');
        }, $html);
    }

    private function runMenu(string $run, int $minimum): ?string
    {
        preg_match_all('~' . self::LINK . '~is', $run, $links);
        if (count($links[0]) < $minimum) {
            return null;
        }
        $html = '';
        foreach ($links[0] as $link) {
            $entry = $this->item($link, '');
            if ($entry === null) {
                return null;
            }
            $html .= $entry;
        }
        $this->menus++;
        $this->links += count($links[0]);
        return '<ul class="' . $this->menuClass() . '">' . $html . '</ul>';
    }

    /**
     * One menu item, or null when the link is not a text link.
     *
     * @param string $link The link, maybe inside formatting tags
     * @param string $liAttributes Attributes of the original <li>
     */
    private function item(string $link, string $liAttributes): ?string
    {
        if (!preg_match('~<a\b([^>]*)>((?:(?!</a>).)*)</a>~is', $link, $a)) {
            return null;
        }
        $attributes = $a[1];
        $content = trim($a[2]);
        $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($content), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        if ($text === '' || mb_strlen($text, 'UTF-8') > self::MAX_TEXT || preg_match('~<(?:img|svg|iframe|video|picture)\b~i', $content)) {
            return null;
        }
        if (!preg_match('~\shref\s*=~i', $attributes)) {
            return null;
        }

        $icon = preg_match('~\bclass\s*=\s*["\'][^"\']*\blocale_~i', $liAttributes) ? 'language' : self::iconFor($text);
        $classes = 'msd_menu_link msd_i_' . $icon;
        if (preg_match('~\shref\s*=\s*(["\'])(.*?)\1~is', $attributes, $href) && $this->current !== ''
            && self::normaliseUrl(html_entity_decode($href[2], ENT_QUOTES | ENT_HTML5, 'UTF-8')) === $this->current
            && !preg_match('~\saria-current\s*=~i', $attributes)) {
            $classes .= ' msd_menu_current';
            $attributes .= ' aria-current="page"';
        }

        return '<li' . $liAttributes . '><a' . $this->withClass($attributes, $classes) . '>'
            . '<span class="msd_menu_icon" aria-hidden="true"></span>'
            . '<span class="msd_menu_text">' . $content . '</span></a></li>';
    }

    private function menuClass(): string
    {
        return 'msd_menu msd_menu_' . $this->style;
    }

    /**
     * Attributes with classes added to the class attribute (or a new one).
     */
    private function withClass(string $attributes, string $classes): string
    {
        $done = false;
        $attributes = preg_replace_callback('~(\sclass\s*=\s*)(["\'])(.*?)\2~is', function ($m) use ($classes, &$done) {
            $done = true;
            return $m[1] . $m[2] . trim($m[3] . ' ' . $classes) . $m[2];
        }, $attributes, 1);
        return $done ? $attributes : rtrim($attributes) . ' class="' . $classes . '"';
    }

    /**
     * Host-less path and query without a trailing slash, for comparing links.
     * A trailing "/index" (the default page or operation of OJS) is dropped,
     * so that ".../journal" and ".../journal/index" are the same page.
     */
    public static function normaliseUrl(string $url): string
    {
        $parts = parse_url(trim($url));
        if ($parts === false || !isset($parts['path'])) {
            return '';
        }
        $path = preg_replace('~(?:/index)+/*$~i', '', rtrim($parts['path'], '/'));
        return strtolower($path) . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }

    /**
     * Position just after the element that opens at $start (balanced on the
     * same tag name), or null when it is not closed.
     */
    private static function elementEnd(string $html, int $start, string $tag): ?int
    {
        $depth = 0;
        $offset = $start;
        $pattern = '~<(/?)' . preg_quote($tag, '~') . '\b[^>]*>~i';
        while (preg_match($pattern, $html, $m, PREG_OFFSET_CAPTURE, $offset)) {
            $offset = $m[0][1] + strlen($m[0][0]);
            $depth += $m[1][0] === '/' ? -1 : 1;
            if ($depth === 0) {
                return $offset;
            }
        }
        return null;
    }
}

