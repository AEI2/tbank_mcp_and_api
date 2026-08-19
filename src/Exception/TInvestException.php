<?php

declare(strict_types=1);

namespace Tbank\Invest\Exception;

class TInvestException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $statusCode = 500,
        public readonly mixed $payload = null,
        public readonly ?string $trackingId = null,
        public readonly string|int|null $codeValue = null,
    ) {
        parent::__construct($message, is_int($codeValue) ? $codeValue : 0);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $data = [
            'error' => $this->getMessage(),
            'status_code' => $this->statusCode,
        ];
        if ($this->codeValue !== null) {
            $data['code'] = $this->codeValue;
        }
        if ($this->trackingId) {
            $data['tracking_id'] = $this->trackingId;
        }
        if ($this->payload !== null) {
            $data['details'] = $this->payload;
        }

        return $data;
    }
}
