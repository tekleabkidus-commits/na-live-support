<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| AC-09 / BR-01 allowlist (step-1 checkpoint ruling 4 — the string-lint shape)
|------------------------------------------------------------------------------
|
| The NoVerifiedAffordanceTest sweep is the BROAD /verif/i on purpose: BR-01
| forbids the CONCEPT (verified, verifiable, verify_badge, VerifyBadge…), and a
| pattern narrowed to survive its own guard misses the next variant. Genuine
| exceptions live HERE, exact-match per (file, lowercased token), one reason
| each — visible and reviewable, never dissolved into the pattern.
|
| An entry allows ONLY the named token in the named file; any other /verif/i
| token in the same file still fails. Adding an entry is a reviewed change to a
| security test's exception list — treat it like a gate skip.
*/

return [
    // The Note-1 drop gate's method is the code VERB "verify" (checking a
    // migration precondition), not an identity-status claim. Named verify()
    // deliberately: the drop-migration author greps for it.
    'app/Domain/Customers/Identity/DeprecatedColumnDropGate.php' => ['verify'],

    // Docblock prose "the drop gate verifies it" — the same verb, about the
    // gate above, in the class that feeds it.
    'app/Domain/Customers/Identity/LegacyIdentityBackfill.php' => ['verifies'],
];
