<?php

namespace App\Actions\Converter\Render;

use RuntimeException;

/**
 * pdf2img could not render a PDF. $retryable separates "this machine had a bad moment" (timeout, out
 * of memory, cannot write) from "this file will never render" (damaged or password protected), so a
 * broken document is not retried forever.
 */
class RenderFailed extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $exitCode = null,
        public readonly string $errorOutput = '',
        public readonly bool $retryable = false,
    ) {
        parent::__construct($message);
    }
}
