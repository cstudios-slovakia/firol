<?php

declare(strict_types=1);

/*
 * Loaded by Composer's autoloader (composer.json → autoload.files), so every
 * entry point — the front controller, the cron scripts, the migrations — runs
 * with it before any line of its own.
 *
 * Dates the app writes as „today" (an Opakovať or follow-up draft, a book
 * entry repeated, deadline notices) come from PHP's default timezone. Only
 * the docker image sets it in php.ini; a server left on UTC would write
 * yesterday's date between midnight and 1–2 a.m. Slovak time. The app serves
 * Slovak technicians, so the zone is fixed here.
 */
date_default_timezone_set('Europe/Bratislava');
