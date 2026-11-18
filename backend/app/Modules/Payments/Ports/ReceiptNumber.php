<?php

namespace App\Modules\Payments\Ports;

final readonly class ReceiptNumber
{
    public function __construct(
        public string $numberingId,
        public string $prefix,
        public int $number,
    ) {}

    public function full(): string
    {
        return $this->prefix.$this->number;
    }
}
