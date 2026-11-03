<?php

declare(strict_types=1);

namespace ProtoBlocks\ContentModel;

/**
 * Small English pluraliser for declared post type and taxonomy names.
 * Only the last word inflects; the input casing is kept.
 */
final class Inflector
{
    private const UNCOUNTABLE = ['media', 'news', 'series', 'staff', 'sheep', 'fish', 'equipment', 'information', 'species', 'money', 'rice'];

    private const IRREGULAR = [
        'person' => 'people',
        'child'  => 'children',
        'man'    => 'men',
        'woman'  => 'women',
        'foot'   => 'feet',
        'tooth'  => 'teeth',
        'mouse'  => 'mice',
        'goose'  => 'geese',
        'ox'     => 'oxen',
    ];

    private const F_WORDS = ['leaf', 'knife', 'life', 'wife', 'half', 'shelf', 'wolf', 'calf', 'loaf', 'thief'];

    private const O_WORDS = ['hero', 'potato', 'tomato', 'echo', 'veto'];

    public static function plural(string $singular): string
    {
        $singular = trim($singular);
        if ($singular === '') {
            return '';
        }

        if (!preg_match('/^(.*?)(\S+)$/su', $singular, $m)) {
            return $singular;
        }
        [, $head, $word] = $m;

        return $head . self::pluraliseWord($word);
    }

    private static function pluraliseWord(string $word): string
    {
        $lower = strtolower($word);
        $upper = self::isUpper($word);

        if (in_array($lower, self::UNCOUNTABLE, true)) {
            return $word;
        }

        if (isset(self::IRREGULAR[$lower])) {
            $plural = self::IRREGULAR[$lower];
            if ($upper) {
                return strtoupper($plural);
            }
            return ctype_upper($word[0]) ? ucfirst($plural) : $plural;
        }

        if (in_array($lower, self::F_WORDS, true)) {
            return preg_replace('/fe?$/i', '', $word) . ($upper ? 'VES' : 'ves');
        }

        if (preg_match('/[^aeiou]y$/i', $word)) {
            return substr($word, 0, -1) . ($upper ? 'IES' : 'ies');
        }

        if (preg_match('/(s|x|z|ch|sh)$/i', $word) || in_array($lower, self::O_WORDS, true)) {
            return $word . ($upper ? 'ES' : 'es');
        }

        return $word . ($upper ? 'S' : 's');
    }

    private static function isUpper(string $word): bool
    {
        return strlen($word) > 1 && $word === strtoupper($word);
    }
}
