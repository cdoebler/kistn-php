<?php

namespace Kistn\Exception;

class ApiException extends InventoryException
{
    public function __construct(
        private readonly int $statusCode,
        private readonly string $responseBody,
        ?\Throwable $previous = null,
    ) {
        parent::__construct("API error {$statusCode}", $statusCode, $previous);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getResponseBody(): string
    {
        return $this->responseBody;
    }
}
