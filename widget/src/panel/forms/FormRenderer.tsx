import type { FunctionComponent } from 'preact';

/** Doc 12 form renderer — a LAZY chunk (NFR-01). Steps render at step 9 (pre-chat, offline, in-thread forms). */
export const FormRenderer: FunctionComponent = () => <section data-nlw-form="" />;
export default FormRenderer;
