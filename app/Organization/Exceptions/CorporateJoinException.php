<?php

namespace App\Organization\Exceptions;

use App\Organization\Enums\CorporateJoinStatus;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * A join attempt that did not produce a membership. Rendered as { "message", "join_status" }.
 */
class CorporateJoinException extends HttpException
{
    public function __construct(public readonly CorporateJoinStatus $joinStatus)
    {
        parent::__construct($joinStatus->httpStatus(), $joinStatus->message());
    }

    public static function because(CorporateJoinStatus $status): self
    {
        return new self($status);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'join_status' => $this->joinStatus->value,
        ], $this->getStatusCode());
    }
}
