<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Routes legitimately outside the permission system — each with a reason.
|------------------------------------------------------------------------------
|
| Key: route URI (as shown by `route:list`) or route name.
| Value: the reason it needs no permission key.
|
| Public ≠ unprotected: webhook/widget routes still authenticate via their
| channel keys (Doc 01 FR-03); they are exempt from STAFF permissions only.
|
*/

return [
    '/' => 'Session 0 placeholder page; replaced by Doc 07 agent app (login-gated then)',
];
