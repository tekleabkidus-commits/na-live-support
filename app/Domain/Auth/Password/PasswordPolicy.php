<?php

declare(strict_types=1);

namespace App\Domain\Auth\Password;

use App\Models\User;
use App\Support\Settings\SettingsRepository;

/**
 * FR-15, in the order the rules should cost (settings-driven, tenant-bound).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * TWO PHASES, BY PRICE. Phase one (length, classes) is string arithmetic and
 * runs always, collecting every failure so the response names them all — the
 * "live policy validation" shape FR-16 wants. Phase two (breach check, reuse
 * history) runs ONLY when phase one passed: each history comparison is an
 * argon2id at ~100 ms (NFR-03) and the breach check is a network call, and
 * spending either on a password already too short buys nothing. The client
 * consequence is documented rather than hidden: a candidate can pass phase one,
 * then fail phase two on the next submit. That is the correct trade — the
 * expensive rules exist to catch a REAL candidate, not to annotate a typo.
 *
 * THE CLASS RULE'S SHAPE IS AN INTERPRETATION, recorded here because Doc 02
 * does not define it: "character-class requirement toggle (default off)" names
 * no classes. Implemented as at least 3 of the 4 classes {lower, upper, digit,
 * other} — the common contractual meaning, chosen over all-4 because FR-15's
 * own stance is "length over composition" and the toggle is off by default.
 * If the owner wants a different shape it is one method and one test.
 *
 * BR-05's platform minimum (length ≥ 10) is NOT enforced here — it is enforced
 * where it cannot be weakened, in the settings registry's validation floor, so
 * no operator write can produce a policy this class would have to distrust.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class PasswordPolicy
{
    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly BreachChecker $breach,
        private readonly PasswordHistory $history,
    ) {}

    /**
     * Throws PasswordPolicyViolation naming every failed rule. Call with the
     * user's tenant bound — the policy is the TENANT's (T-10).
     */
    public function enforce(User $user, string $candidate): void
    {
        $errors = [];

        $minLength = $this->intSetting('auth.password.min_length', 12);
        if (mb_strlen($candidate) < $minLength) {
            $errors['length'] = [__('auth.password.rule_length', ['min' => $minLength])];
        }

        if ($this->boolSetting('auth.password.require_classes') && ! $this->meetsClassRequirement($candidate)) {
            $errors['classes'] = [__('auth.password.rule_classes')];
        }

        if ($errors !== []) {
            throw new PasswordPolicyViolation($errors);
        }

        if ($this->boolSetting('auth.password.breach_check') && $this->breach->isBreached($candidate)) {
            $errors['breached'] = [__('auth.password.rule_breached')];
        }

        if ($this->history->isReused($user, $candidate, $this->intSetting('auth.password.history', 5))) {
            $errors['reuse'] = [__('auth.password.rule_reuse')];
        }

        if ($errors !== []) {
            throw new PasswordPolicyViolation($errors);
        }
    }

    private function meetsClassRequirement(string $candidate): bool
    {
        $classes = 0;
        $classes += preg_match('/\p{Ll}/u', $candidate) === 1 ? 1 : 0;
        $classes += preg_match('/\p{Lu}/u', $candidate) === 1 ? 1 : 0;
        $classes += preg_match('/\p{N}/u', $candidate) === 1 ? 1 : 0;
        $classes += preg_match('/[^\p{L}\p{N}]/u', $candidate) === 1 ? 1 : 0;

        return $classes >= 3;
    }

    private function intSetting(string $key, int $fallback): int
    {
        $value = $this->settings->get($key);

        return is_numeric($value) ? (int) $value : $fallback;
    }

    private function boolSetting(string $key): bool
    {
        return (bool) $this->settings->get($key);
    }
}
