<?php

/**
 * @file plugins/themes/manuscriptDaffa/classes/MsdCountries.inc.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class MsdCountries
 *
 * @brief Country helpers: localized names, local SVG flags and the
 *  "country from the affiliation" guess used for the issue statistics.
 */

require_once __DIR__ . '/MsdCache.inc.php';

class MsdCountries
{
    /** Cache lifetime of the country list (one day) */
    public const LIST_TTL = 86400;

    /** @var array<string,array<string,string>> code => name per locale */
    protected static $names = [];

    /** @var array<string,array<string,string>> normalized name => code per locale */
    protected static $lookup = [];

    /**
     * Common abbreviations and alternative names (English and Indonesian)
     * that are not in the ISO 3166 list in the form people write them.
     */
    protected const ALIASES = [
        'usa' => 'US', 'u s a' => 'US', 'us' => 'US', 'u s' => 'US', 'america' => 'US',
        'united states' => 'US', 'united states of america' => 'US', 'amerika' => 'US',
        'amerika serikat' => 'US', 'as' => 'US',
        'uk' => 'GB', 'u k' => 'GB', 'united kingdom' => 'GB', 'great britain' => 'GB',
        'britain' => 'GB', 'england' => 'GB', 'scotland' => 'GB', 'wales' => 'GB',
        'northern ireland' => 'GB', 'inggris' => 'GB', 'britania raya' => 'GB',
        'uae' => 'AE', 'u a e' => 'AE', 'emirates' => 'AE', 'united arab emirates' => 'AE',
        'uni emirat arab' => 'AE', 'persatuan emirat arab' => 'AE',
        'south korea' => 'KR', 'korea' => 'KR', 'republic of korea' => 'KR', 'korea south' => 'KR',
        'korea republic of' => 'KR', 'korea selatan' => 'KR', 'korsel' => 'KR',
        'north korea' => 'KP', 'korea utara' => 'KP',
        'russia' => 'RU', 'rusia' => 'RU', 'russian federation' => 'RU',
        'vietnam' => 'VN', 'viet nam' => 'VN',
        'iran' => 'IR', 'islamic republic of iran' => 'IR',
        'turkey' => 'TR', 'turkiye' => 'TR', 'turki' => 'TR',
        'czech republic' => 'CZ', 'czechia' => 'CZ', 'ceko' => 'CZ',
        'taiwan' => 'TW', 'tanzania' => 'TZ', 'bolivia' => 'BO', 'venezuela' => 'VE',
        'syria' => 'SY', 'suriah' => 'SY', 'laos' => 'LA', 'moldova' => 'MD', 'brunei' => 'BN',
        'brunei darussalam' => 'BN', 'palestine' => 'PS', 'palestina' => 'PS',
        'hong kong' => 'HK', 'macau' => 'MO', 'macao' => 'MO',
        'china' => 'CN', 'prc' => 'CN', 'p r china' => 'CN', 'pr china' => 'CN',
        "people's republic of china" => 'CN', 'peoples republic of china' => 'CN',
        'tiongkok' => 'CN', 'cina' => 'CN', 'rrt' => 'CN',
        'holland' => 'NL', 'the netherlands' => 'NL', 'netherlands' => 'NL', 'belanda' => 'NL',
        'jerman' => 'DE', 'jepang' => 'JP', 'arab saudi' => 'SA', 'saudi arabia' => 'SA',
        'saudi' => 'SA', 'ksa' => 'SA', 'mesir' => 'EG', 'filipina' => 'PH', 'philippines' => 'PH',
        'singapura' => 'SG', 'prancis' => 'FR', 'perancis' => 'FR', 'spanyol' => 'ES',
        'italia' => 'IT', 'kanada' => 'CA', 'selandia baru' => 'NZ', 'irak' => 'IQ', 'iraq' => 'IQ',
        'yordania' => 'JO', 'brasil' => 'BR', 'brazil' => 'BR', 'meksiko' => 'MX',
        'swiss' => 'CH', 'swedia' => 'SE', 'norwegia' => 'NO', 'denmark' => 'DK',
        'finlandia' => 'FI', 'polandia' => 'PL', 'yunani' => 'GR', 'portugis' => 'PT',
        'afrika selatan' => 'ZA', 'maroko' => 'MA', 'aljazair' => 'DZ', 'tunisia' => 'TN',
        'libya' => 'LY', 'sudan' => 'SD', 'yaman' => 'YE', 'yemen' => 'YE', 'oman' => 'OM',
        'kuwait' => 'KW', 'qatar' => 'QA', 'katar' => 'QA', 'bahrain' => 'BH', 'lebanon' => 'LB',
        'libanon' => 'LB', 'afganistan' => 'AF', 'bangladesh' => 'BD', 'nepal' => 'NP',
        'sri lanka' => 'LK', 'myanmar' => 'MM', 'burma' => 'MM', 'kamboja' => 'KH',
        'cambodia' => 'KH', 'timor leste' => 'TL', 'timor-leste' => 'TL', 'east timor' => 'TL',
        'papua nugini' => 'PG', 'papua new guinea' => 'PG', 'nigeria' => 'NG', 'kenya' => 'KE',
        'ethiopia' => 'ET', 'etiopia' => 'ET', 'ghana' => 'GH', 'uzbekistan' => 'UZ',
        'kazakhstan' => 'KZ', 'kazakstan' => 'KZ', 'ukraina' => 'UA', 'ukraine' => 'UA',
        'hungaria' => 'HU', 'rumania' => 'RO', 'romania' => 'RO', 'irlandia' => 'IE',
        'ireland' => 'IE', 'belgia' => 'BE', 'austria' => 'AT', 'kolombia' => 'CO', 'cile' => 'CL',
        'chili' => 'CL', 'argentina' => 'AR', 'peru' => 'PE', 'kuba' => 'CU', 'ivory coast' => 'CI',
        'cote d ivoire' => 'CI', 'congo' => 'CG', 'dr congo' => 'CD', 'drc' => 'CD',
        'kirgizstan' => 'KG', 'kyrgyzstan' => 'KG', 'tajikistan' => 'TJ', 'tajikistan republic' => 'TJ',
        'pakistan' => 'PK', 'india' => 'IN', 'malaysia' => 'MY', 'thailand' => 'TH', 'australia' => 'AU',
    ];

    /**
     * Country code => name in the current UI locale.
     *
     * The list is cached for a day per locale.
     *
     * @return array<string,string> Sorted by name
     */
    public static function all(): array
    {
        $locale = self::locale();
        if (isset(self::$names[$locale])) {
            return self::$names[$locale];
        }

        $build = function () {
            $list = [];
            foreach (self::isoCountries() as $country) {
                $list[strtoupper($country->getAlpha2())] = $country->getLocalName();
            }
            uasort($list, function ($a, $b) {
                return strcoll($a, $b);
            });
            return $list;
        };

        try {
            $list = MsdCache::remember('manuscriptDaffa_countries_' . $locale, self::LIST_TTL, $build);
        } catch (Throwable $e) {
            try {
                $list = $build();
            } catch (Throwable $e) {
                $list = [];
            }
        }

        return self::$names[$locale] = is_array($list) ? $list : [];
    }

    /**
     * Localized name of a country code, or the code itself if unknown.
     */
    public static function name(?string $code): string
    {
        $code = strtoupper(trim((string) $code));
        if ($code === '') {
            return '';
        }
        $all = self::all();
        return $all[$code] ?? $code;
    }

    /**
     * Is a two-letter code a valid country code?
     */
    public static function isValid(?string $code): bool
    {
        $code = strtoupper(trim((string) $code));
        if (!preg_match('/^[A-Z]{2}$/', $code)) {
            return false;
        }
        $all = self::all();
        return empty($all) || isset($all[$code]);
    }

    /**
     * URL of the local flag for a country code, or null if the theme has no flag.
     */
    public static function flagUrl(string $code, string $pluginUrl): ?string
    {
        $file = strtolower($code);
        if (!preg_match('/^[a-z]{2}$/', $file) || !is_file(dirname(__DIR__) . '/flags/' . $file . '.svg')) {
            return null;
        }
        return rtrim($pluginUrl, '/') . '/flags/' . $file . '.svg';
    }

    /**
     * Guess a country code from the last comma-separated part of an affiliation.
     *
     * "Universitas Indonesia, Depok, Indonesia" -> ID
     * "Universitas Indonesia" -> null (no country at the end, no guess)
     */
    public static function fromAffiliation(?string $affiliation): ?string
    {
        $affiliation = trim(strip_tags((string) $affiliation));
        if ($affiliation === '' || !str_contains($affiliation, ',')) {
            return null;
        }
        $parts = explode(',', $affiliation);
        $last = self::normalize((string) end($parts));
        if ($last === '') {
            return null;
        }
        $lookup = self::lookup();
        return $lookup[$last] ?? null;
    }

    /**
     * Normalized name => code for the UI locale, English and the aliases.
     */
    protected static function lookup(): array
    {
        $locale = self::locale();
        if (isset(self::$lookup[$locale])) {
            return self::$lookup[$locale];
        }
        $lookup = [];
        foreach (self::all() as $code => $name) {
            $key = self::normalize($name);
            if ($key !== '' && !isset($lookup[$key])) {
                $lookup[$key] = $code;
            }
        }
        // ISO names in English
        try {
            foreach (self::isoCountries() as $country) {
                $key = self::normalize($country->getName());
                if ($key !== '' && !isset($lookup[$key])) {
                    $lookup[$key] = strtoupper($country->getAlpha2());
                }
                if (method_exists($country, 'getOfficialName') && $country->getOfficialName()) {
                    $key = self::normalize($country->getOfficialName());
                    if ($key !== '' && !isset($lookup[$key])) {
                        $lookup[$key] = strtoupper($country->getAlpha2());
                    }
                }
            }
        } catch (Throwable $e) {
            // Keep what we have
        }
        foreach (self::ALIASES as $alias => $code) {
            $lookup[self::normalize($alias)] = $code;
        }
        return self::$lookup[$locale] = $lookup;
    }

    /**
     * Current UI locale of OJS 3.3 (e.g. id_ID).
     */
    protected static function locale(): string
    {
        return class_exists('AppLocale') ? (string) AppLocale::getLocale() : 'en_US';
    }

    /**
     * The ISO 3166 country list with names in the UI language, as used by
     * the author form of OJS 3.3.
     */
    protected static function isoCountries()
    {
        return (new \Sokil\IsoCodes\IsoCodesFactory())->getCountries();
    }

    /**
     * Lowercase, accents removed, punctuation turned into spaces.
     */
    public static function normalize(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = mb_strtolower(trim($text), 'UTF-8');
        if (function_exists('transliterator_transliterate')) {
            $converted = transliterator_transliterate('Any-Latin; Latin-ASCII', $text);
            if (is_string($converted)) {
                $text = $converted;
            }
        } else {
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
            if (is_string($converted)) {
                $text = $converted;
            }
        }
        $text = preg_replace("/[^a-z0-9']+/", ' ', strtolower($text));
        $text = str_replace("'", '', $text);
        return trim(preg_replace('/\s+/', ' ', $text));
    }
}
