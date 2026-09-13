<?php

declare(strict_types=1);

namespace Payroll\Domain\EarningLine;

use Payroll\Domain\EarningLine\Exception\InvalidAdjustmentComment;

/**
 * The mandatory explanation attached to a manual adjustment.
 *
 * Validation lives here and runs on the command side only. Recorded events carry
 * the comment as a plain string: history is valid by definition, and re-validating
 * it during replay would make old streams unreadable the day a rule is tightened.
 */
final readonly class AdjustmentComment
{
    private function __construct(public string $value) {}

    public static function fromString(string $comment): self
    {
        // The comment is free text that ends up in an immutable log, where an
        // encoding mistake can never be corrected. Reject invalid bytes early;
        // json_encode would otherwise return false and store "" in the stream.
        if (! mb_check_encoding($comment, 'UTF-8')) {
            throw InvalidAdjustmentComment::isNotValidUtf8();
        }

        $trimmed = preg_replace('/^[\p{Z}\s]+|[\p{Z}\s]+$/u', '', $comment) ?? '';

        if ($trimmed === '') {
            throw InvalidAdjustmentComment::isEmpty();
        }

        return new self($trimmed);
    }

    public function toString(): string
    {
        return $this->value;
    }
}
