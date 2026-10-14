<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

/**
 * Business rule failure in the advertisement flow. Renders as the usual
 * { success: false, message, errors } JSON whatever the Accept header.
 */
class AdvertisementException extends Exception
{
    public function __construct(string $message, private int $status = 422, private array $errors = [])
    {
        parent::__construct($message);
    }

    public static function invalid(string $field, string $message): self
    {
        return new self($message, 422, [$field => [$message]]);
    }

    public static function invalidMany(array $errors, string $message = 'The given data was invalid.'): self
    {
        return new self($message, 422, $errors);
    }

    public static function forbidden(string $message = 'You are not allowed to access this advertisement.'): self
    {
        return new self($message, 403);
    }

    public static function notFound(string $message = 'Advertisement not found.'): self
    {
        return new self($message, 404);
    }

    public static function conflict(string $message): self
    {
        return new self($message, 409);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function render(): JsonResponse
    {
        $body = [
            'success' => false,
            'message' => $this->getMessage(),
        ];

        if ($this->errors) {
            $body['errors'] = $this->errors;
        }

        return response()->json($body, $this->status);
    }
}
