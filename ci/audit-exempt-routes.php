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
    /*
     | Doc 01's three inbound ingress probes (routes/api.php).
     |
     | They are POST because that is the honest verb for a channel webhook, and the
     | audit-coverage gate reasonably treats POST as mutating. These three mutate
     | NOTHING: each resolves a tenant, applies the FR-05 state matrix, and returns
     | what it resolved. There is no state change to audit, so an audit entry here
     | would be a record of nothing having happened.
     |
     | THIS EXEMPTION IS TEMPORARY BY CONSTRUCTION. Doc 05 replaces the telegram
     | route's handler, Doc 13 the widget's, Doc 14 the DLR's — and each of those
     | handlers DOES mutate (it creates a conversation, a message, a delivery
     | receipt). When they land, the handler changes but the route name does not, so
     | the exemption would silently carry over. Each owning document must therefore
     | delete its line here and register real audit coverage; noted in
     | docs/BUILD-STATUS.md as an inherited task rather than left to be noticed.
     */
    'foundation.webhook.telegram' => 'Doc 01 ingress probe: resolves and reports, mutates nothing. Doc 05 must remove this line when it adds a real handler.',
    'foundation.widget.session' => 'Doc 01 ingress probe: resolves and reports, mutates nothing. Doc 13 must remove this line when it adds a real handler.',
    'foundation.webhook.sms_dlr' => 'Doc 01 ingress probe: resolves and reports, mutates nothing. Doc 14 must remove this line when it adds a real handler.',
];
