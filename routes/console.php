<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('transaction:status')->everyFifteenSeconds();
