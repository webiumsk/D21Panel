<?php

namespace Tests\Unit;

use App\Services\RegWatch\SnapshotDiff;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class RegWatchSnapshotDiffTest extends TestCase
{
    #[Test]
    public function identical_snapshots_produce_an_empty_diff(): void
    {
        $this->assertSame('', SnapshotDiff::diff("a\nb", "a\nb"));
    }

    #[Test]
    public function added_and_removed_lines_are_reported(): void
    {
        $diff = SnapshotDiff::diff("keep\nold line", "keep\nnew line");

        $this->assertStringContainsString('- old line', $diff);
        $this->assertStringContainsString('+ new line', $diff);
        $this->assertStringNotContainsString('keep', $diff);
    }

    #[Test]
    public function duplicate_lines_only_diff_when_their_count_changes(): void
    {
        $this->assertSame('', SnapshotDiff::diff("x\nx", "x\nx"));

        $diff = SnapshotDiff::diff("x\nx", 'x');
        $this->assertSame('- x', $diff);
    }

    #[Test]
    public function whitespace_only_changes_are_ignored(): void
    {
        $this->assertSame('', SnapshotDiff::diff("  a  \n\nb", "a\nb\n"));
    }

    #[Test]
    public function long_diffs_are_truncated(): void
    {
        $old = '';
        $new = implode("\n", array_fill(0, 200, str_repeat('y', 100)));

        $diff = SnapshotDiff::diff($old, $new, 500);

        $this->assertLessThan(600, mb_strlen($diff));
        $this->assertStringContainsString('[diff truncated]', $diff);
    }
}
