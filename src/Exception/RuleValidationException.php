<?php

namespace Dunea\MemberPromotion\Exception;

use RuntimeException;

class RuleValidationException extends RuntimeException
{
    /** @var array<string, string> */
    private array $errors;

    /** @param array<string, string> $errors */
    public function __construct(array $errors)
    {
        parent::__construct(reset($errors) ?: 'Invalid promotion rule.');
        $this->errors = $errors;
    }

    /** @return array<string, string> */
    public function errors(): array
    {
        return $this->errors;
    }
}
