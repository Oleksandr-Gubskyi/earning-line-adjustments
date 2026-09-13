<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\EarningLine;

use Payroll\Domain\EarningLine\AdjustmentComment;
use Payroll\Domain\EarningLine\Exception\InvalidAdjustmentComment;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AdjustmentCommentTest extends TestCase
{
    public function test_it_accepts_the_comments_from_the_assignment(): void
    {
        $comments = [
            'Employee declined dental benefit; reversing deduction',
            'Late correction: missed approved overtime bonus',
            'Minor rounding adjustment',
            'Correcting mistake in adjustment #4',
        ];

        foreach ($comments as $comment) {
            self::assertSame($comment, AdjustmentComment::fromString($comment)->toString());
        }
    }

    public function test_it_trims_surrounding_whitespace_but_keeps_the_text_intact(): void
    {
        $comment = AdjustmentComment::fromString("  Reversing a  deduction\n");

        self::assertSame('Reversing a  deduction', $comment->toString());
    }

    #[DataProvider('blankComments')]
    public function test_it_rejects_a_blank_comment(string $input): void
    {
        $this->expectException(InvalidAdjustmentComment::class);

        AdjustmentComment::fromString($input);
    }

    public static function blankComments(): iterable
    {
        yield 'empty string' => [''];
        yield 'spaces' => ['   '];
        yield 'tab and newline' => ["\t\n"];
        yield 'non breaking space' => ["\u{00A0}"];
    }

    public function test_it_rejects_invalid_utf8(): void
    {
        // json_encode returns false on malformed UTF-8, which would otherwise be
        // written into an immutable log as an empty payload.
        $this->expectException(InvalidAdjustmentComment::class);

        AdjustmentComment::fromString("bad \x80 byte");
    }

    #[DataProvider('commentsWithControlCharacters')]
    public function test_it_rejects_control_characters(string $input): void
    {
        // "Non-empty after trim" is satisfied by a NUL byte, which is not a reason
        // any human could read off an audit trail.
        $this->expectException(InvalidAdjustmentComment::class);

        AdjustmentComment::fromString($input);
    }

    public static function commentsWithControlCharacters(): iterable
    {
        yield 'nul byte' => ["Reversing\0deduction"];
        yield 'bell' => ["Reversing\x07deduction"];
        yield 'embedded newline' => ["Reversing\ndeduction"];
    }

    #[DataProvider('unicodeComments')]
    public function test_it_preserves_valid_unicode(string $input): void
    {
        $comment = AdjustmentComment::fromString($input);

        self::assertSame($input, $comment->toString());

        // The comment must survive the trip through the event payload unchanged.
        $encoded = json_encode($comment->toString(), JSON_THROW_ON_ERROR);
        self::assertSame($input, json_decode($encoded, true, 512, JSON_THROW_ON_ERROR));
    }

    public static function unicodeComments(): iterable
    {
        yield 'typographic minus' => ["Reversing \u{2212}45.55"];
        yield 'accented name' => ['Correction for Zoë Müller'];
        yield 'cyrillic' => ['Коригування премії'];
        yield 'quotes and apostrophes' => ['Employee\'s "declined" benefit'];
        yield 'emoji' => ['Approved by payroll 🙂'];
    }
}
