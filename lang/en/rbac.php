<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| RBAC strings — English (Doc 03 T-02, SEC-02, §14; C-08; N-03)
|------------------------------------------------------------------------------
*/

return [
    // The staff 403's detail (T-02's diagram: the deny NAMES the key for
    // staff clients — an authenticated colleague learning what their own role
    // lacks, never an enumeration surface).
    'denied' => 'Your role does not include the :permission permission.',
];
