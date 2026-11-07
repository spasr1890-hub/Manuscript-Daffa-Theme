<?php

/**
 * @file plugins/themes/manuscriptDaffa/tools/check-php73.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * Flags syntax newer than PHP 7.3 (the minimum of OJS 3.3) in the given
 * files, using the tokenizer of the running PHP: arrow functions, nullsafe
 * operator, match, attributes, enums, readonly, first-class callables,
 * typed properties, "mixed" types, ??=, constructor promotion.
 * CI also lints the files with a real PHP 7.3.
 *
 *   php tools/check-php73.php file.php [...]
 */

$problems = 0;
foreach (array_slice($argv, 1) as $file) {
    $tokens = token_get_all(file_get_contents($file));
    $count = count($tokens);
    $significant = [];
    foreach ($tokens as $i => $t) {
        if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        $significant[] = $t;
    }
    $n = count($significant);
    $report = function ($t, $what) use ($file, &$problems) {
        $line = is_array($t) ? $t[2] : '?';
        echo "{$file}:{$line}: {$what}\n";
        $problems++;
    };
    $named = function ($t, string $id) {
        return is_array($t) && defined($id) && $t[0] === constant($id);
    };
    for ($i = 0; $i < $n; $i++) {
        $t = $significant[$i];
        foreach (['T_FN' => 'arrow function (PHP 7.4)', 'T_NULLSAFE_OBJECT_OPERATOR' => 'nullsafe ?-> (PHP 8.0)', 'T_MATCH' => 'match (PHP 8.0)',
            'T_ATTRIBUTE' => 'attribute (PHP 8.0)', 'T_ENUM' => 'enum (PHP 8.1)', 'T_READONLY' => 'readonly (PHP 8.1)', 'T_COALESCE_EQUAL' => '??= (PHP 7.4)'] as $id => $what) {
            if ($named($t, $id)) {
                $report($t, $what);
            }
        }
        // First-class callable: ( ... )
        if ($t === '(' && isset($significant[$i + 2]) && is_array($significant[$i + 1]) && $significant[$i + 1][0] === T_ELLIPSIS && $significant[$i + 2] === ')') {
            $report($significant[$i + 1], 'first-class callable syntax (PHP 8.1)');
        }
        // Typed property: visibility/static/var followed by a type and a variable, outside a parameter list
        if (is_array($t) && in_array($t[0], [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_VAR], true)) {
            $j = $i + 1;
            while (isset($significant[$j]) && is_array($significant[$j]) && $significant[$j][0] === T_STATIC) {
                $j++;
            }
            if (isset($significant[$j]) && ($significant[$j] === '?' || (is_array($significant[$j]) && in_array($significant[$j][0], [T_STRING, T_ARRAY, T_CALLABLE], true) && strtolower($significant[$j][1]) !== 'function'))) {
                $k = $j + ($significant[$j] === '?' ? 2 : 1);
                if (isset($significant[$k]) && is_array($significant[$k]) && $significant[$k][0] === T_VARIABLE) {
                    // Inside a constructor parameter list this is promotion, outside a typed property
                    $report($significant[$j], 'typed property or constructor promotion (PHP 7.4/8.0)');
                }
            }
        }
        // "mixed" as a type
        if (is_array($t) && $t[0] === T_STRING && strtolower($t[1]) === 'mixed') {
            $prev = $significant[$i - 1] ?? null;
            if ($prev === ':' || $prev === '(' || $prev === ',' || $prev === '?') {
                $report($t, 'mixed type (PHP 8.0)');
            }
        }
    }
}
echo $problems ? "{$problems} problems\n" : "No syntax newer than PHP 7.3 found\n";
exit($problems ? 1 : 0);
