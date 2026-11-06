<?php

/**
 * @file plugins/themes/manuscriptDaffa/classes/IssueStats.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class IssueStats
 *
 * @brief Article, page, author and country counts of an issue. Used by the
 *  "Issue Coverage" box and the statistic cards on the Archives page.
 */

namespace APP\plugins\themes\manuscriptDaffa\classes;

use APP\facades\Repo;
use APP\submission\Submission;
use Illuminate\Support\Facades\Cache;
use PKP\facades\Locale;
use Throwable;

class IssueStats
{
    /** Cache lifetime (30 minutes) */
    public const TTL = 1800;

    /** Bump to invalidate cached results after a change in the calculation */
    public const CACHE_VERSION = 3;

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
            $numbers = array_values(array_filter($numbers, fn ($n) => $n !== ''));
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
            $publishedSubmissions[$key]['articles'] = array_map(fn ($d) => $d['article'], $decorated);
        }
        return $publishedSubmissions;
    }

    /**
     * Statistics of one issue, cached for 30 minutes.
     *
     * @return array{articles:int,pages:?int,authors:int,countries:array<string,int>,known:int,foreign:?int,foreignPct:?int,guessed:int}
     */
    public static function get(int $contextId, int $issueId, ?string $homeCountry): array
    {
        $homeCountry = $homeCountry ? strtoupper($homeCountry) : '';
        $key = sprintf(
            'manuscriptDaffa_issue_v%d_%d_%d_%s_%s',
            self::CACHE_VERSION,
            $contextId,
            $issueId,
            $homeCountry ?: '-',
            Locale::getLocale()
        );
        try {
            return Cache::remember($key, self::TTL, fn () => self::compute($contextId, $issueId, $homeCountry));
        } catch (Throwable $e) {
            return self::compute($contextId, $issueId, $homeCountry);
        }
    }

    /**
     * Calculate the statistics without the cache.
     */
    public static function compute(int $contextId, int $issueId, string $homeCountry = ''): array
    {
        $submissions = Repo::submission()
            ->getCollector()
            ->filterByContextIds([$contextId])
            ->filterByIssueIds([$issueId])
            ->filterByStatus([Submission::STATUS_PUBLISHED])
            ->getMany();

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
                $name = Countries::normalize((string) $author->getFullName(false));
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

        $names = Countries::all();
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
        if ($code !== '' && Countries::isValid($code)) {
            return [$code, false];
        }
        $affiliations = [];
        try {
            foreach ($author->getAffiliations() as $affiliation) {
                $affiliations[] = (string) $affiliation->getLocalizedName();
            }
        } catch (Throwable $e) {
            // Older data model: a plain localized affiliation string
            if (method_exists($author, 'getLocalizedAffiliation')) {
                $affiliations[] = (string) $author->getLocalizedAffiliation();
            }
        }
        foreach (array_reverse($affiliations) as $affiliation) {
            $guess = Countries::fromAffiliation($affiliation);
            if ($guess) {
                return [$guess, true];
            }
        }
        return [null, false];
    }
}
