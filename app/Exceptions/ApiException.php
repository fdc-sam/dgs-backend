<?php

namespace App\Exceptions;

use RuntimeException;

/** Domain error rendered as the API error envelope with a machine-readable code. */
class ApiException extends RuntimeException
{
    /** @param  array<string, list<string>>  $errors */
    public function __construct(
        string $message,
        public readonly string $errorCode = 'CONFLICT',
        public readonly int $status = 409,
        public readonly array $errors = [],
    ) {
        parent::__construct($message);
    }

    public static function conflict(string $message): self
    {
        return new self($message, 'CONFLICT', 409);
    }

    public static function forbidden(string $message): self
    {
        return new self($message, 'FORBIDDEN', 403);
    }

    public static function notFound(string $message): self
    {
        return new self($message, 'NOT_FOUND', 404);
    }

    /** @param  array<string, list<string>>  $errors */
    public static function validation(string $message, array $errors = []): self
    {
        return new self($message, 'VALIDATION_ERROR', 422, $errors);
    }
}
