<?php

namespace nsql\database\orm;

/**
 * İngilizce tablo adı çözümü: sınıf adı → snake_case → çoğul.
 *
 * Kurallar bilinçli olarak basittir; kapsanmayan bir kelime için modelde `$table` belirtin.
 */
final class Inflector
{
    private const IRREGULAR = [
        'person' => 'people',
        'man' => 'men',
        'woman' => 'women',
        'child' => 'children',
        'mouse' => 'mice',
        'goose' => 'geese',
        'tooth' => 'teeth',
        'foot' => 'feet',
        'ox' => 'oxen',
        'leaf' => 'leaves',
        'life' => 'lives',
        'knife' => 'knives',
        'wife' => 'wives',
        'half' => 'halves',
        'wolf' => 'wolves',
        'shelf' => 'shelves',
        'thief' => 'thieves',
        'potato' => 'potatoes',
        'tomato' => 'tomatoes',
        'hero' => 'heroes',
        'echo' => 'echoes',
        'quiz' => 'quizzes',
        'criterion' => 'criteria',
        'phenomenon' => 'phenomena',
        'analysis' => 'analyses',
        'crisis' => 'crises',
        'thesis' => 'theses',
        'index' => 'indices',
        'matrix' => 'matrices',
        'vertex' => 'vertices',
    ];

    private const UNCOUNTABLE = [
        'data', 'equipment', 'fish', 'information', 'media', 'metadata', 'money', 'news',
        'rice', 'series', 'sheep', 'species', 'deer', 'feedback', 'staff', 'software', 'traffic',
    ];

    /**
     * Snake_case kelime grubunun yalnızca son kelimesini çoğullar: user_category → user_categories.
     */
    public static function plural(string $word): string
    {
        if ($word === '') {
            return $word;
        }

        $parts = explode('_', $word);
        $last = (string) array_pop($parts);
        $parts[] = self::plural_word($last);

        return implode('_', $parts);
    }

    /**
     * UserProfile → user_profile, HTTPRequest → http_request, OAuth2Token → o_auth2_token
     */
    public static function snake(string $name): string
    {
        $name = (string) preg_replace('/([A-Z]+)([A-Z][a-z])/', '$1_$2', $name);
        $name = (string) preg_replace('/([a-z\d])([A-Z])/', '$1_$2', $name);

        return strtolower(str_replace(['-', ' '], '_', $name));
    }

    /**
     * Sınıf adından (namespace'li olabilir) tablo adı: App\Models\BlogPost → blog_posts
     */
    public static function table_for_class(string $class): string
    {
        $short = substr((string) strrchr('\\' . $class, '\\'), 1);

        return self::plural(self::snake($short));
    }

    private static function plural_word(string $word): string
    {
        $lower = strtolower($word);
        if (in_array($lower, self::UNCOUNTABLE, true)) {
            return $word;
        }
        if (isset(self::IRREGULAR[$lower])) {
            return self::IRREGULAR[$lower];
        }
        if (in_array($lower, self::IRREGULAR, true)) {
            return $word;
        }

        return match (true) {
            (bool) preg_match('/[^aeiou]y$/', $lower) => substr($word, 0, -1) . 'ies',
            (bool) preg_match('/(s|x|z|ch|sh)$/', $lower) => $word . 'es',
            default => $word . 's',
        };
    }
}
