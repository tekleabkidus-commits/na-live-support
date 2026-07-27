<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Mutating routes legitimately outside audit coverage — each with a reason.
|------------------------------------------------------------------------------
|
| Key: route URI (as shown by `route:list`) or route name.
| Value: the reason this mutation carries no audit action.
|
| This list should stay very short. "We forgot" is not a reason.
|
*/

return [
    // Intentionally empty in Session 0 — there are no mutating routes yet.
];
