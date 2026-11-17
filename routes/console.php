<?php

use Illuminate\Support\Facades\Schedule;

// Safety net for lost webhooks: poll generations that have gone quiet.
Schedule::command('pixelflow:reconcile-generations')
    ->everyMinute()
    ->withoutOverlapping();
