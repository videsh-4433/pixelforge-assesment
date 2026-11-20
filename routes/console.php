<?php

use Illuminate\Support\Facades\Schedule;

// Fallback for lost or delayed webhooks.
Schedule::command('generations:sync')->everyMinute()->withoutOverlapping();
