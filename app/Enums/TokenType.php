<?php

namespace App\Enums;

/**
 * The four kinds of token Pixelforge bills, each at its own rate. The
 * values are the keys Pixelforge uses in `usage` and `rates`.
 */
enum TokenType: string
{
    case InputText = 'input_text_tokens';
    case InputImage = 'input_image_tokens';
    case Output = 'output_tokens';
    case Reasoning = 'reasoning_tokens';
}
