<?php

namespace App\Services\RegWatch;

/**
 * Line-based diff between two normalized source snapshots. Intentionally
 * simple (removed/added lines, no ordering context): the output feeds human
 * review and the Claude relevance classifier, not a patch tool.
 */
class SnapshotDiff
{
    /**
     * @return string empty when the snapshots are line-identical
     */
    public static function diff(string $old, string $new, int $maxChars = 20000): string
    {
        $oldLines = self::lines($old);
        $newLines = self::lines($new);

        // Multiset difference so repeated lines are only reported when their
        // count actually changes.
        $removed = self::multisetDiff($oldLines, $newLines);
        $added = self::multisetDiff($newLines, $oldLines);

        $parts = [];
        foreach ($removed as $line) {
            $parts[] = '- '.$line;
        }
        foreach ($added as $line) {
            $parts[] = '+ '.$line;
        }

        $diff = implode("\n", $parts);
        if (mb_strlen($diff) > $maxChars) {
            $diff = mb_substr($diff, 0, $maxChars)."\n... [diff truncated]";
        }

        return $diff;
    }

    /** @return list<string> */
    private static function lines(string $text): array
    {
        $lines = preg_split('/\R/u', $text) ?: [];

        return array_values(array_filter(array_map('trim', $lines), fn (string $l) => $l !== ''));
    }

    /**
     * Lines of $a not covered by $b, respecting duplicates.
     *
     * @param  list<string>  $a
     * @param  list<string>  $b
     * @return list<string>
     */
    private static function multisetDiff(array $a, array $b): array
    {
        $counts = array_count_values($b);
        $result = [];
        foreach ($a as $line) {
            if (($counts[$line] ?? 0) > 0) {
                $counts[$line]--;

                continue;
            }
            $result[] = $line;
        }

        return $result;
    }
}
