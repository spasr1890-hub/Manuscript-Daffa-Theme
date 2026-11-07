<?php

/**
 * @file plugins/themes/manuscriptDaffa/classes/MsdIssueStats.inc.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class MsdIssueStats
 *
 * @brief Article, page, author and country counts of an issue. Used by the
 *  "Issue Coverage" box and the statistic cards on the Archives page.
 */

use Illuminate\Database\Capsule\Manager as Capsule;

require_once __DIR__ . '/MsdCache.inc.php';
require_once __DIR__ . '/MsdCountries.inc.php';

class MsdIssueStats
{
    /**
     * Cache lifetime (one day). The cache key also holds a stamp of the
     * issue's articles (see stamps()), so publishing, unpublishing or editing
     * an article of the issue refreshes the statistics right away.
     */
    public const TTL = 86400;

    /** Bump to invalidate cached results after a change in the calculation */
    public const CACHE_VERSION = 4;

    /**
     * Number of pages in a "Pages" value.
     *
     * "863-875" = 13, "12" = 1, "e12-e20" = 9 (letters are ignored),
     * "1-5, 9" = 6, "" = null (not counted).
     */
    public static function countPages(?string $pages): ?int
    {
        $pages = trim((string) $pages);
        if ($pages === '') {
            return null;
        }
        $total = 0;
        $found = false;
        foreach (preg_split('/[,;]+/', $pages) as $part) {
            $part = preg_replace('/[^0-9\-\x{2013}\x{2014}]+/u', '', $part);
            $numbers = preg_split('/[\-\x{2013}\x{2014}]+/u', $part, -1, PREG_SPLIT_NO_EMPTY);
            $numbers = array_values(array_filter($numbers, function ($n) {
                return $n !== '';
            }));
            if (!count($numbers)) {
                continue;
            }
            $found = true;
            if (count($numbers) === 1) {
                $total += 1;
                continue;
            }
            $first = (int) $numbers[0];
            $last = (int) $numbers[count($numbers) - 1];
            $total += $last >= $first ? $last - $first + 1 : 1;
        }
        return $found ? $total : null;
    }

    /**
     * First page number of a "Pages" value ("1029-1038" = 1029, "e12" = 12),
     * or null when it has no number.
     */
    public static function startPage(?string $pages): ?int
    {
        if (preg_match('/\d+/', (string) $pages, $m)) {
            return (int) $m[0];
        }
        return null;
    }

    /**
     * Sort the articles of every section by their first page. Articles without
     * page numbers go to the end of their section; ties keep their order.
     *
     * @param array $publishedSubmissions [sectionId => ['articles' => Submission[], ...]]
     */
    public static function sortByPages(array $publishedSubmissions): array
    {
        foreach ($publishedSubmissions as $key => $section) {
            if (empty($section['articles']) || !is_array($section['articles'])) {
                continue;
            }
            $articles = array_values($section['articles']);
            $decorated = [];
            foreach ($articles as $i => $article) {
                $publication = $article->getCurrentPublication();
                $decorated[] = [
                    'start' => $publication ? self::startPage($publication->getData('pages')) : null,
                    'i' => $i,
                    'article' => $article,
                ];
            }
            usort($decorated, function ($a, $b) {
                if ($a['start'] === null && $b['start'] === null) {
                    return $a['i'] <=> $b['i'];
                }
                if ($a['start'] === null) {
                    return 1;
                }
                if ($b['start'] === null) {
                    return -1;
                }
                return [$a['start'], $a['i']] <=> [$b['start'], $b['i']];
            });
            $publishedSubmissions[$key]['articles'] = array_map(function ($d) {
                return $d['article'];
            }, $decorated);
        }
        return $publishedSubmissions;
    }

    /**
     * Statistics of one issue, cached for 30 minutes.
     *
     * @return array{articles:int,pages:?int,authors:int,countries:array<string,int>,known:int,foreign:?int,foreignPct:?int,guessed:int}
     */
    public static function get(int $contextId, int $issueId, ?string $homeCountry, string $stamp = '', bool $refresh = false): array
    {
        $homeCountry = $homeCountry ? strtoupper($homeCountry) : '';
        $key = sprintf(
            'manuscriptDaffa_issue_v%d_%d_%d_%s_%s_%s',
            self::CACHE_VERSION,
            $contextId,
            $issueId,
            $homeCountry ?: '-',
            (class_exists('AppLocale') ? AppLocale::getLocale() : 'en_US'),
            $stamp ?: '-'
        );
        try {
            if ($refresh) {
                $stats = self::compute($contextId, $issueId, $homeCountry);
                MsdCache::put($key, $stats);
                return $stats;
            }
            return MsdCache::remember($key, self::TTL, function () use ($contextId, $issueId, $homeCountry) {
                return self::compute($contextId, $issueId, $homeCountry);
            });
        } catch (Throwable $e) {
            return self::compute($contextId, $issueId, $homeCountry);
        }
    }

    /**
     * A short stamp per issue that changes when its published articles
     * change: number of articles, latest modification of their current
     * publications, number of authors and the newest author id, and the
     * modification date of the issue. One grouped query for all issues.
     *
     * @param int[] $issueIds
     *
     * @return array<int,string> issue id => stamp ('' when it can not be read)
     */
    public static function stamps(int $contextId, array $issueIds): array
    {
        $issueIds = array_values(array_unique(array_map('intval', $issueIds)));
        if (!$issueIds) {
            return [];
        }
        $parts = array_fill_keys($issueIds, []);
        try {
            // OJS 3.3 keeps the issue of a publication in publication_settings
            $rows = Capsule::table('publications as p')
                ->join('submissions as s', 's.current_publication_id', '=', 'p.publication_id')
                ->join('publication_settings as ps', function ($join) {
                    $join->on('ps.publication_id', '=', 'p.publication_id')->where('ps.setting_name', '=', 'issueId');
                })
                ->leftJoin('authors as a', 'a.publication_id', '=', 'p.publication_id')
                ->where('s.context_id', $contextId)
                ->where('s.status', STATUS_PUBLISHED)
                ->whereIn('ps.setting_value', array_map('strval', $issueIds))
                ->groupBy('ps.setting_value')
                ->selectRaw('ps.setting_value AS issue_id, COUNT(DISTINCT p.publication_id) AS pubs, MAX(p.last_modified) AS modified, COUNT(a.author_id) AS authors, MAX(a.author_id) AS last_author')
                ->get();
            foreach ($rows as $row) {
                $parts[(int) $row->issue_id] = [$row->pubs, $row->modified, $row->authors, $row->last_author];
            }
            $issues = Capsule::table('issues')->whereIn('issue_id', $issueIds)->pluck('last_modified', 'issue_id');
            foreach ($issues as $issueId => $modified) {
                $parts[(int) $issueId][] = $modified;
            }
        } catch (Throwable $e) {
            return array_fill_keys($issueIds, '');
        }
        return array_map(function ($p) {
            return substr(md5(json_encode($p)), 0, 12);
        }, $parts);
    }

    /**
     * Calculate the statistics without the cache.
     */
    public static function compute(int $contextId, int $issueId, string $homeCountry = ''): array
    {
        $submissions = Services::get('submission')->getMany([
            'contextId' => $contextId,
            'issueIds' => [$issueId],
            'status' => STATUS_PUBLISHED,
        ]);

        $articles = 0;
        $pages = 0;
        $hasPages = false;
        $authors = [];

        foreach ($submissions as $submission) {
            $publication = $submission->getCurrentPublication();
            if (!$publication) {
                continue;
            }
            $articles++;
            $count = self::countPages($publication->getData('pages'));
            if ($count !== null) {
                $pages += $count;
                $hasPages = true;
            }
            foreach ($publication->getData('authors') ?? [] as $author) {
                $name = MsdCountries::normalize((string) $author->getFullName(false));
                $email = mb_strtolower(trim((string) $author->getEmail()), 'UTF-8');
                $id = $name . '|' . $email;
                if ($name === '' && $email === '') {
                    $id = 'author-' . $author->getId();
                }
                if (isset($authors[$id]) && $authors[$id]['country']) {
                    continue;
                }
                [$country, $guessed] = self::countryOf($author);
                if (!isset($authors[$id]) || $country) {
                    $authors[$id] = ['country' => $country, 'guessed' => $guessed];
                }
            }
        }

        $countries = [];
        $guessed = 0;
        $known = 0;
        $foreign = 0;
        foreach ($authors as $author) {
            if (!$author['country']) {
                continue;
            }
            $known++;
            $countries[$author['country']] = ($countries[$author['country']] ?? 0) + 1;
            if ($author['guessed']) {
                $guessed++;
            }
            if ($homeCountry && $author['country'] !== $homeCountry) {
                $foreign++;
            }
        }

        $names = MsdCountries::all();
        uksort($countries, function ($a, $b) use ($countries, $names) {
            return [$countries[$b], $names[$a] ?? $a] <=> [$countries[$a], $names[$b] ?? $b];
        });

        return [
            'articles' => $articles,
            'pages' => $hasPages ? $pages : null,
            'authors' => count($authors),
            'countries' => $countries,
            'known' => $known,
            'foreign' => $homeCountry ? $foreign : null,
            'foreignPct' => $homeCountry ? self::percent($foreign, $known) : null,
            'guessed' => $guessed,
        ];
    }

    /**
     * Percentage of foreign authors among the authors with a known country.
     * Never 0% when there is a foreign author and never 100% when there is a
     * domestic one.
     */
    public static function percent(int $foreign, int $known): ?int
    {
        if ($known <= 0) {
            return null;
        }
        $pct = (int) round($foreign / $known * 100);
        if ($foreign > 0 && $pct === 0) {
            $pct = 1;
        }
        if ($foreign < $known && $pct === 100) {
            $pct = 99;
        }
        return $pct;
    }

    /**
     * Country of an author: the Country field, otherwise the end of the
     * affiliation.
     *
     * @return array{0:?string,1:bool} [code, guessed from the affiliation]
     */
    public static function countryOf($author): array
    {
        $code = strtoupper(trim((string) $author->getCountry()));
        if ($code !== '' && MsdCountries::isValid($code)) {
            return [$code, false];
        }
        // OJS 3.3: one localized affiliation string per author
        $affiliations = [(string) $author->getLocalizedData('affiliation')];
        foreach (array_reverse($affiliations) as $affiliation) {
            $guess = MsdCountries::fromAffiliation($affiliation);
            if ($guess) {
                return [$guess, true];
            }
        }
        return [null, false];
    }
}
