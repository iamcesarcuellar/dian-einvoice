<?php

namespace App\Modules\Delivery\Pdf;

/**
 * A PDF as a renderer produced it: its bytes and how many pages it has.
 */
final readonly class RenderedDocument
{
    public function __construct(
        public string $contents,
        public int $pages,
    ) {}
}
