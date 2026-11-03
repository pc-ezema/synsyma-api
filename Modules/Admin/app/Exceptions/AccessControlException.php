<?php

namespace Modules\Admin\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

class AccessControlException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }

    public function report(): bool
    {
        return false; // an expected refusal, not a bug
    }

    public function render($request): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], $this->status);
    }
}