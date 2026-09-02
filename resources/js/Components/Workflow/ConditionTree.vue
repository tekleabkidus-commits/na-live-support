<script setup lang="ts">
// Doc 12 FR-02 / §8 / §13 — the condition tree builder: all/any groups nested, per-row fact →
// type-aware operator → value, keyword-set picker for message facts; FULLY KEYBOARD OPERABLE
// (add / group / move-into / move-out are BUTTONS; tree semantics with aria-level). The shape
// it edits is EXACTLY the engine's ({all|any: [leaf|group]}, leaf = {fact, op, value}), so the
// dry-run reads the same object. Recursive: a group renders itself.
import { computed } from 'vue';

type Leaf = { fact: string; op: string; value: unknown; keyword_set_id?: string };
type Group = { all?: Node[]; any?: Node[] };
type Node = Leaf | Group;

const props = defineProps<{
    copy: Record<string, unknown>;
    modelValue: Group;
    level?: number;
    facts: string[];
    keywordSets: { id: string; name: string; active: boolean }[];
    removedOptions?: Record<string, string[]>; // fact → values no longer offered (EC-08 warning)
}>();
const emit = defineEmits<{ (e: 'update:modelValue', v: Group): void; (e: 'remove'): void; (e: 'outdent', node: Node): void }>();
// eslint-disable-next-line @typescript-eslint/no-explicit-any
const c = props.copy as any;

const OPERATORS: Record<string, string[]> = {
    text: ['equals', 'contains', 'is_empty'],
    number: ['equals', 'gt', 'lt', 'between', 'is_empty'],
    list: ['in', 'contains', 'is_empty'],
    boolean: ['equals'],
};
function kind(fact: string): keyof typeof OPERATORS {
    if (fact.endsWith('.tags') || fact === 'message.body') return 'list';
    if (['ticket.age_minutes', 'ai.sentiment', 'ai.confidence', 'ai.urgency'].includes(fact))
        return 'number';
    if (['customer.blocked', 'time.within_business_hours'].includes(fact)) return 'boolean';
    return 'text';
}
const mode = computed<'all' | 'any'>(() => ('any' in props.modelValue ? 'any' : 'all'));
const children = computed<Node[]>(() => (props.modelValue[mode.value] ?? []) as Node[]);
const level = computed(() => props.level ?? 1);

function replace(nodes: Node[]): void {
    emit('update:modelValue', { [mode.value]: nodes } as Group);
}
function setMode(next: 'all' | 'any'): void {
    emit('update:modelValue', { [next]: children.value } as Group);
}
function isGroup(node: Node): node is Group {
    return 'all' in node || 'any' in node;
}
function addCondition(): void {
    replace([
        ...children.value,
        { fact: props.facts[0] ?? 'ticket.status', op: 'equals', value: '' },
    ]);
}
function addGroup(): void {
    replace([...children.value, { all: [] }]);
}
function update(index: number, node: Node): void {
    const next = [...children.value];
    next[index] = node;
    replace(next);
}
function remove(index: number): void {
    replace(children.value.filter((_, i) => i !== index));
}
function indent(index: number): void {
    // move INTO the previous sibling group (creating one when needed)
    const next = [...children.value];
    const node = next[index];
    const prev = next[index - 1];
    if (!node) return;
    if (prev && isGroup(prev)) {
        const key = 'any' in prev ? 'any' : 'all';
        (prev as Record<string, Node[]>)[key] = [
            ...((prev as Record<string, Node[]>)[key] ?? []),
            node,
        ];
        next.splice(index, 1);
    } else {
        next.splice(index, 1, { all: [node] });
    }
    replace(next);
}
function leafValue(leaf: Leaf): string {
    return Array.isArray(leaf.value)
        ? leaf.value.join(', ')
        : leaf.value === null || leaf.value === undefined
          ? ''
          : String(leaf.value);
}
function setLeafValue(index: number, leaf: Leaf, raw: string): void {
    const k = kind(leaf.fact);
    let value: unknown = raw;
    if (leaf.op === 'in' || leaf.op === 'between' || k === 'list')
        value = raw
            .split(',')
            .map((s) => s.trim())
            .filter((s) => s !== '');
    else if (k === 'number') value = raw === '' ? '' : Number(raw);
    else if (k === 'boolean') value = raw === 'true';
    update(index, { ...leaf, value });
}
function removedWarning(leaf: Leaf): boolean {
    const removed = props.removedOptions?.[leaf.fact] ?? [];
    const values = Array.isArray(leaf.value) ? leaf.value.map(String) : [String(leaf.value)];
    return values.some((v) => removed.includes(v));
}
</script>

<template>
    <div
        role="group"
        :aria-level="level"
        class="rounded-md border p-2"
        :style="{
            borderColor: 'var(--ds-border)',
            background: level === 1 ? 'var(--ds-panel-bg)' : 'var(--ds-inset-bg)',
        }"
    >
        <div class="mb-2 flex items-center gap-2 text-xs">
            <select
                :value="mode"
                :aria-label="c.rules.panel_conditions"
                class="rounded-md border px-2 py-1"
                :style="{ borderColor: 'var(--ds-border)' }"
                @change="setMode(($event.target as HTMLSelectElement).value as 'all' | 'any')"
            >
                <option value="all">{{ c.rules.group_all }}</option>
                <option value="any">{{ c.rules.group_any }}</option>
            </select>
            <button
                type="button"
                class="rounded-md border px-2 py-1"
                :style="{ borderColor: 'var(--ds-border)' }"
                data-testid="wf-cond-add"
                @click="addCondition"
            >
                {{ c.rules.add_condition }}
            </button>
            <button
                type="button"
                class="rounded-md border px-2 py-1"
                :style="{ borderColor: 'var(--ds-border)' }"
                data-testid="wf-cond-add-group"
                @click="addGroup"
            >
                {{ c.rules.add_group }}
            </button>
            <button
                v-if="level > 1"
                type="button"
                class="ms-auto rounded-md border px-2 py-1"
                :style="{ borderColor: 'var(--ds-border)' }"
                @click="emit('remove')"
            >
                {{ c.rules.remove }}
            </button>
        </div>
        <ul role="tree" class="flex flex-col gap-2">
            <li
                v-for="(node, index) in children"
                :key="index"
                role="treeitem"
                :aria-level="level + 1"
            >
                <ConditionTree
                    v-if="isGroup(node)"
                    :copy="copy"
                    :model-value="node"
                    :level="level + 1"
                    :facts="facts"
                    :keyword-sets="keywordSets"
                    :removed-options="removedOptions"
                    @update:model-value="(v) => update(index, v)"
                    @remove="remove(index)"
                />
                <div
                    v-else
                    class="flex flex-wrap items-center gap-2 text-xs"
                    :data-testid="`wf-cond-leaf-${level}-${index}`"
                >
                    <select
                        :value="(node as Leaf).fact"
                        :aria-label="c.rules.fact"
                        class="rounded-md border px-2 py-1"
                        :style="{ borderColor: 'var(--ds-border)' }"
                        @change="
                            update(index, {
                                ...(node as Leaf),
                                fact: ($event.target as HTMLSelectElement).value,
                                op: 'equals',
                                value: '',
                            })
                        "
                    >
                        <option v-for="f in facts" :key="f" :value="f">{{ f }}</option>
                    </select>
                    <select
                        :value="(node as Leaf).op"
                        :aria-label="c.rules.operator"
                        class="rounded-md border px-2 py-1"
                        :style="{ borderColor: 'var(--ds-border)' }"
                        @change="
                            update(index, {
                                ...(node as Leaf),
                                op: ($event.target as HTMLSelectElement).value,
                            })
                        "
                    >
                        <option
                            v-for="op in OPERATORS[kind((node as Leaf).fact)]"
                            :key="op"
                            :value="op"
                        >
                            {{ op }}
                        </option>
                    </select>
                    <template v-if="(node as Leaf).fact === 'message.body'">
                        <select
                            :value="(node as Leaf).keyword_set_id ?? ''"
                            :aria-label="c.rules.keyword_set"
                            class="rounded-md border px-2 py-1"
                            :style="{ borderColor: 'var(--ds-border)' }"
                            @change="
                                update(index, {
                                    ...(node as Leaf),
                                    keyword_set_id: ($event.target as HTMLSelectElement).value,
                                    value: ($event.target as HTMLSelectElement).value,
                                })
                            "
                        >
                            <option value="">—</option>
                            <option v-for="set in keywordSets" :key="set.id" :value="set.id">
                                {{ set.name }}
                            </option>
                        </select>
                        <span
                            v-if="
                                keywordSets.find(
                                    (s) => s.id === (node as Leaf).keyword_set_id && !s.active,
                                )
                            "
                            class="text-[11px]"
                            :style="{ color: 'var(--ds-danger-text)' }"
                            >{{ c.rules.keyword_set_retired_warning }}</span
                        >
                    </template>
                    <input
                        v-else-if="(node as Leaf).op !== 'is_empty'"
                        :value="leafValue(node as Leaf)"
                        :aria-label="c.rules.value"
                        class="rounded-md border px-2 py-1"
                        :style="{ borderColor: 'var(--ds-border)' }"
                        @change="
                            setLeafValue(
                                index,
                                node as Leaf,
                                ($event.target as HTMLInputElement).value,
                            )
                        "
                    />
                    <span
                        v-if="removedWarning(node as Leaf)"
                        class="text-[11px]"
                        :style="{ color: 'var(--ds-danger-text)' }"
                        >{{ c.rules.removed_option_warning }}</span
                    >
                    <span class="ms-auto flex gap-1">
                        <button
                            type="button"
                            class="rounded-md border px-2 py-1"
                            :style="{ borderColor: 'var(--ds-border)' }"
                            :aria-label="c.rules.indent"
                            @click="indent(index)"
                        >
                            ⇥
                        </button>
                        <button
                            v-if="level > 1"
                            type="button"
                            class="rounded-md border px-2 py-1"
                            :style="{ borderColor: 'var(--ds-border)' }"
                            :aria-label="c.rules.outdent"
                            @click="
                                $emit('outdent', node);
                                remove(index);
                            "
                        >
                            ⇤
                        </button>
                        <button
                            type="button"
                            class="rounded-md border px-2 py-1"
                            :style="{ borderColor: 'var(--ds-border)' }"
                            :aria-label="c.rules.remove"
                            @click="remove(index)"
                        >
                            ✕
                        </button>
                    </span>
                </div>
            </li>
        </ul>
    </div>
</template>
