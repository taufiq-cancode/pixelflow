<?php

/*
|--------------------------------------------------------------------------
| PixelFlow business rules
|--------------------------------------------------------------------------
*/

return [

    // Markup added on top of Pixelforge's cost, in basis points
    // (2500 = 25%). Applied once per generation to the total provider
    // cost and rounded up to a whole nanodollar. See DECISIONS.md §5.
    'markup_bps' => (int) env('PIXELFLOW_MARKUP_BPS', 2500),

];
