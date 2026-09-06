/*
 * Doc 12 FR-20 / Doc 13 FR-06, FR-15 — THE FORM RENDERER (B3-S05 F03–F04,
 * F09–F10; a LAZY chunk): the immutable version's schema rendered with the
 * widget's theme; whole-step submits through `POST forms/{version}/steps`
 * (the server validates, the visitor holds the state); refusals INLINE with
 * the field named, calm copy from the server's keys; `visible_when` honoured.
 * Pre-chat: optional skips, required blocks (the tab bar is the Panel's to
 * suppress). Offline: one step → the real offline-tagged ticket and its
 * receipt with the MASKED phone shown back (FR-15).
 */
import type { FunctionComponent } from 'preact';
import { useEffect, useState } from 'preact/hooks';
import type { WidgetApi } from '../api';
import { t } from '../i18n';
import { language } from '../state';
import type { FormContract, FormField } from '../types';

interface Props {
    api: WidgetApi;
    versionId: string;
    mode: 'form' | 'offline';
    required: boolean; // pre-chat: required blocks, optional skips
    intro?: string;
    title?: string;
    onComplete: (answers: Record<string, unknown>) => void;
    onSkip?: () => void;
    onReceipt?: (receipt: { ticket_number: number; phone_masked: string | null }) => void;
}

function visible(field: FormField, answers: Record<string, unknown>): boolean {
    const w = field.visible_when;
    if (!w) return true;
    return w.equals === undefined ? !!answers[w.field] : answers[w.field] === w.equals;
}

export const FormRenderer: FunctionComponent<Props> = (p) => {
    const lang = language.value;
    const [contract, setContract] = useState<FormContract | null>(null);
    const [step, setStep] = useState(0);
    const [answers, setAnswers] = useState<Record<string, unknown>>({});
    const [errors, setErrors] = useState<Record<string, string[]>>({});
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        void p.api.formSchema(p.versionId).then((c) => setContract(c));
    }, [p.api, p.versionId]);

    if (!contract) return <div class="nlw-form" data-role="form-loading" />;
    const steps = contract.schema.steps;
    const current = steps[Math.min(step, steps.length - 1)];
    if (!current) return null;
    const fields = current.fields.filter((f) => visible(f, answers));

    const submit = async (): Promise<void> => {
        setBusy(true);
        const res = await p.api.formStep(
            p.versionId,
            p.mode === 'offline'
                ? { mode: 'offline', complete: true, answers }
                : { mode: 'form', step_index: step, answers, complete: step === steps.length - 1 },
        );
        setBusy(false);
        if (!res.ok) {
            const errs = (res.body.errors ?? {}) as Record<string, string[]>;
            setErrors(errs);
            return;
        }
        setErrors({});
        if (p.mode === 'offline') {
            p.onReceipt?.(res.body as { ticket_number: number; phone_masked: string | null });
            return;
        }
        const merged = { ...answers, ...((res.body.answers as Record<string, unknown>) ?? {}) };
        setAnswers(merged);
        if (res.body.complete === true || step >= steps.length - 1) p.onComplete(merged);
        else setStep(step + 1);
    };

    return (
        <form
            class="nlw-form"
            data-role={p.mode === 'offline' ? 'offline-form' : 'prechat'}
            data-required={String(p.required)}
            onSubmit={(e) => {
                e.preventDefault();
                void submit();
            }}
        >
            {p.title ? <h2 class="nlw-form-title">{p.title}</h2> : null}
            {p.intro ? <p class="nlw-form-intro">{p.intro}</p> : null}
            {!p.title ? (
                <span class="nlw-pill">
                    {p.required ? t('prechat.required') : t('prechat.optional')}
                </span>
            ) : null}
            {fields.map((f) => {
                const label = f.label[lang] ?? Object.values(f.label)[0] ?? f.key;
                const err = errors[f.key]?.[0];
                const id = 'nlw-f-' + f.key;
                const common = {
                    id,
                    name: f.key,
                    'aria-invalid': err ? true : undefined,
                    'aria-describedby': err ? id + '-err' : undefined,
                };
                return (
                    <div key={f.key} class="nlw-field" data-field={f.key}>
                        <label for={id}>
                            {label}
                            {!f.required ? <small> · {t('prechat.optional')}</small> : null}
                        </label>
                        {f.type === 'textarea' || f.key === 'story' || f.key === 'message' ? (
                            <textarea
                                {...common}
                                rows={3}
                                value={String(answers[f.key] ?? '')}
                                onInput={(e) =>
                                    setAnswers({ ...answers, [f.key]: e.currentTarget.value })
                                }
                            />
                        ) : f.type === 'radio' || f.type === 'dropdown' ? (
                            <select
                                {...common}
                                value={String(answers[f.key] ?? '')}
                                onChange={(e) =>
                                    setAnswers({ ...answers, [f.key]: e.currentTarget.value })
                                }
                            >
                                <option value="" />
                                {(f.options ?? []).map((o) => (
                                    <option key={o.value} value={o.value}>
                                        {o.label[lang] ?? o.value}
                                    </option>
                                ))}
                            </select>
                        ) : f.type === 'file' ? (
                            // SEC-04 (step 13b): a file field renders INERT — no input, no upload
                            // path. Uploads are the COMPOSER's, under the adapter's capability
                            // (G05); a form never collects a file, so nothing interactive ships.
                            <p class="nlw-field-note" data-role="file-inert">
                                {t('prechat.file_note')}
                            </p>
                        ) : f.type === 'checkbox' ? (
                            <input
                                {...common}
                                type="checkbox"
                                checked={!!answers[f.key]}
                                onChange={(e) =>
                                    setAnswers({ ...answers, [f.key]: e.currentTarget.checked })
                                }
                            />
                        ) : (
                            <input
                                {...common}
                                type={
                                    f.type === 'phone'
                                        ? 'tel'
                                        : f.type === 'email'
                                          ? 'email'
                                          : f.type === 'number'
                                            ? 'number'
                                            : 'text'
                                }
                                inputMode={f.type === 'phone' ? 'tel' : undefined}
                                value={String(answers[f.key] ?? '')}
                                onInput={(e) =>
                                    setAnswers({ ...answers, [f.key]: e.currentTarget.value })
                                }
                            />
                        )}
                        {err ? (
                            <p id={id + '-err'} class="nlw-error" role="alert">
                                {t(err)}
                            </p>
                        ) : null}
                    </div>
                );
            })}
            <div class="nlw-form-actions">
                <button type="submit" class="nlw-btn nlw-primary" disabled={busy}>
                    {p.mode === 'offline'
                        ? t('offline.send')
                        : step === steps.length - 1
                          ? t('prechat.start')
                          : t('prechat.continue')}
                </button>
                {!p.required && p.onSkip ? (
                    <button type="button" class="nlw-btn" onClick={p.onSkip}>
                        {t('prechat.skip')}
                    </button>
                ) : null}
            </div>
        </form>
    );
};
export default FormRenderer;
