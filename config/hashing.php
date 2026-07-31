<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Password hashing (Doc 02 NFR-03, CLAUDE.md §3)
|------------------------------------------------------------------------------
|
| NFR-03: "Password hashing: argon2id tuned to ~100 ms server cost; parameters
| reviewed annually."
|
| WHY ARGON2ID AND NOT BCRYPT. Laravel's default is bcrypt, which is fine but
| memory-cheap: an attacker with GPUs gets far more guesses per second against
| bcrypt than against a memory-hard function. argon2id is the current
| password-hashing standard and is memory-hard by construction, so the attacker
| has to buy RAM per parallel guess rather than just cores.
|
| WHY ~100 ms IS THE TARGET, AND THE TENSION IT SITS IN. The cost is a direct
| trade against §12's shift-start spike: 500 agents in 10 minutes is ~50 logins
| per second at peak, and each login spends one hash verification. Too cheap and
| offline cracking gets easy; too expensive and the login surface becomes a
| self-inflicted denial of service at exactly the moment every agent arrives.
|
| The parameters below are the classic balance point for that budget on server
| hardware: 64 MiB of memory, 4 passes, 2 threads. They are DEV-INDICATIVE, not
| measured on production hardware — Doc 19's L1 profile owns the measurement, and
| tests/Performance asserts the shape rather than claiming the number.
|
| These values are also why NFR-01's 400 ms p99 login budget is comfortable at one
| verification and would NOT be at four: the password-reuse check (FR-15) runs up
| to `auth.password.history` verifications and therefore belongs on the password
| CHANGE path only, never on login.
|
| REVIEWING THE PARAMETERS (the "annually" clause) is a real operation, not a
| note: raise the cost here and every user's stored hash is upgraded on their
| next successful login by StaffUserProvider::rehashPasswordIfRequired(). No mass
| rehash is possible — passwords are not recoverable — so the upgrade is
| necessarily gradual, and that is by design.
|
*/

return [

    'driver' => env('HASH_DRIVER', 'argon2id'),

    'bcrypt' => [
        'rounds' => (int) env('BCRYPT_ROUNDS', 12),
        'verify' => true,
        'limit' => null,
    ],

    'argon' => [
        // KiB. 65536 KiB = 64 MiB per hash in flight.
        'memory' => (int) env('ARGON_MEMORY', 65536),
        'threads' => (int) env('ARGON_THREADS', 2),
        'time' => (int) env('ARGON_TIME', 4),
        'verify' => true,
    ],

    'rehash_on_login' => true,

];
