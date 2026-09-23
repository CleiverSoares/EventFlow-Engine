<?php

use Illuminate\Support\Facades\Broadcast;

/*
| Public lab channel — local load-demo board (no auth).
| Do not expose this pattern for tenant-private data in production.
*/

Broadcast::channel('exports.lab', function () {
    return true;
});
