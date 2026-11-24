<?php

declare(strict_types=1);

namespace bjsmasth\Salesforce\Exception;

class Salesforce extends \Exception
{
    /**
     * @param array<int, array<string, mixed>> $errors Error objects returned by Salesforce, if any.
     */
    public function __construct(
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
        protected array $errors = [],
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * The error objects returned by Salesforce, e.g. [['message' => '...', 'errorCode' => 'NOT_FOUND']].
     *
     * @return array<int, array<string, mixed>>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
