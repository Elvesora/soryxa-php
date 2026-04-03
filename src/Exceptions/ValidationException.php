<?php

namespace Elvesora\SoryxaPHP\Exceptions;

/**
 * 422 error: VALIDATION_ERROR
 */
class ValidationException extends SoryxaException {
    public function getValidationErrors(): array {
        return $this->responseBody['errors'] ?? [];
    }
}
