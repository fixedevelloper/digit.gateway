<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('transaction:status')->everyFifteenSeconds();
Schedule::command('transaction:reconcile')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('monitor:check --quiet-ok')->everyMinute()->withoutOverlapping();
