<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Runs daily; the command itself checks which landlords are actually due
// based on their chosen frequency (weekly/monthly) and last_report_sent_at.
Schedule::command('reports:send-scheduled')->daily();