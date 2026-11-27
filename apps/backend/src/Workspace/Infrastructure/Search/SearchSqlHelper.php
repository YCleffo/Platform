<?php

declare(strict_types=1);

namespace App\Workspace\Infrastructure\Search;

final class SearchSqlHelper
{
    public static function escapeLike(string $value): string
    {
        $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
        return '%' . $escaped . '%';
    }

    /** @param list<string> $uuids */
    public static function uuidArrayLiteral(array $uuids): string
    {
        return '{' . implode(',', $uuids) . '}';
    }

    public static function matchExpr(string $expr, string $qParam = ':q', string $likeParam = ':likeQ'): string
    {
        return "(
            to_tsvector('russian', {$expr}) @@ plainto_tsquery('russian', {$qParam})
            OR to_tsvector('simple', {$expr}) @@ plainto_tsquery('simple', {$qParam})
            OR {$expr} ILIKE {$likeParam} ESCAPE '!'
            OR similarity({$expr}, {$qParam}) >= 0.22
            OR (char_length({$qParam}) >= 3 AND word_similarity({$qParam}, {$expr}) >= 0.48)
        )";
    }

    public static function scoreExpr(string $expr, string $qParam = ':q', string $likeParam = ':likeQ'): string
    {
        return "(
            ts_rank_cd(to_tsvector('russian', {$expr}), plainto_tsquery('russian', {$qParam})) * 2.0
            + ts_rank_cd(to_tsvector('simple', {$expr}), plainto_tsquery('simple', {$qParam})) * 1.5
            + COALESCE(similarity({$expr}, {$qParam}), 0.0)
            + COALESCE(word_similarity({$qParam}, {$expr}), 0.0) * 0.5
            + CASE WHEN {$expr} ILIKE {$likeParam} ESCAPE '!' THEN 0.5 ELSE 0.0 END
        )";
    }

    public static function snippet(string $text, string $query, int $maxLength = 140): string
    {
        $clean = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        if ($clean === '') {
            return '';
        }
        if (mb_strlen($clean) <= $maxLength) {
            return $clean;
        }
        $pos = mb_stripos($clean, $query);
        if ($pos === false) {
            return mb_substr($clean, 0, $maxLength - 1) . '…';
        }
        $start = max(0, $pos - (int) floor($maxLength / 3));
        $slice = mb_substr($clean, $start, $maxLength);
        return ($start > 0 ? '…' : '') . $slice . ($start + mb_strlen($slice) < mb_strlen($clean) ? '…' : '');
    }

    /** @return array<string, mixed> */
    public static function item(
        string $entityType,
        string $entityId,
        string $category,
        string $title,
        string $subtitle,
        string $snippet,
        string $route,
        string $module,
        string $organizationId,
        ?string $updatedAt,
        float $score,
    ): array {
        return [
            'id' => $entityType . ':' . $entityId,
            'entityType' => $entityType,
            'entityId' => $entityId,
            'category' => $category,
            'title' => $title,
            'subtitle' => $subtitle,
            'snippet' => $snippet,
            'route' => $route,
            'module' => $module,
            'organizationId' => $organizationId,
            'updatedAt' => $updatedAt,
            'score' => round($score, 4),
        ];
    }
}
