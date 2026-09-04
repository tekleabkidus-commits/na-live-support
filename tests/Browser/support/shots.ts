import { execFileSync } from 'node:child_process';

/**
 * P-F1-SHOTS (S13, advisor-ordered after F1-FO-9): the BUILD side of every
 * frame-vs-build screenshot pair lands under an UNTRACKED path — `test-results/`
 * is gitignored — so a battery never rewrites the committed F1 evidence
 * (142 files per run before this). The committed comparison sets under
 * docs/evidence/F1-<batch>/shots/ are the record the owner accepted; a fresh set
 * for a deliberate comparison is written by setting F1_SHOTS_ROOT.
 * Assertions are untouched: only the directory the shots are written to moved.
 */
export function shotsDir(batch: 'F1-B8' | 'F1-B9' | 'F1-B12'): string {
    const dir = `${process.env.F1_SHOTS_ROOT ?? 'test-results/f1-shots'}/${batch}/shots`;
    execFileSync('mkdir', ['-p', dir]);
    return dir;
}
