<?php

declare(strict_types=1);

namespace App\Domain\Routing\Rg;

/** Every way a ticket could leave a queue — BR-02 permits exactly one for RG. */
enum Diversion: string
{
    case Rule = 'rule';
    case Boost = 'boost';
    case Strategy = 'strategy';
    case BulkManual = 'bulk_manual';
    case AiSuggestion = 'ai_suggestion';
    case RuleTransfer = 'rule_transfer';
    case Automation = 'automation';
    case IndividualManualReassign = 'individual_manual_reassign';
}
