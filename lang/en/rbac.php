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

    // §8 warnings and error states (B6-S07..S10 draw these).
    'warning' => [
        'soft_limit' => 'You have more roles than we would recommend. Consider consolidating before creating another.',
        'override_sprawl' => 'More than the recommended number of people carry an exception for this permission — that is usually the signal to create a role.',
    ],
    'blocked' => [
        'last_admin' => 'This is the last person who can manage roles and users. Give someone else those rights first.',
        'role_assigned' => 'This role is assigned to :count person(s) right now. Move them to another role first.',
    ],
    'conflict' => [
        'role_edited' => 'Someone else changed this role while you were editing it. Review their changes and re-apply yours.',
    ],
];
