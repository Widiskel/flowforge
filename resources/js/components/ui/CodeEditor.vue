<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { EditorState } from '@codemirror/state'
import { EditorView, keymap, lineNumbers, highlightActiveLineGutter, highlightActiveLine, drawSelection } from '@codemirror/view'
import { defaultKeymap, history, historyKeymap, indentWithTab } from '@codemirror/commands'
import { javascript } from '@codemirror/lang-javascript'
import { bracketMatching, indentOnInput, foldGutter, foldKeymap } from '@codemirror/language'
import { closeBrackets, closeBracketsKeymap, autocompletion, completionKeymap } from '@codemirror/autocomplete'
import { oneDark } from '@codemirror/theme-one-dark'

const props = withDefaults(
    defineProps<{
        modelValue: string
        placeholder?: string
        minHeight?: string
        maxHeight?: string
        readonly?: boolean
    }>(),
    {
        placeholder: '',
        minHeight: '200px',
        maxHeight: '480px',
        readonly: false,
    },
)

const emit = defineEmits<{ (e: 'update:modelValue', value: string): void }>()

const host = ref<HTMLElement | null>(null)
let view: EditorView | null = null

function buildState(value: string): EditorState {
    return EditorState.create({
        doc: value,
        extensions: [
            lineNumbers(),
            foldGutter(),
            highlightActiveLine(),
            highlightActiveLineGutter(),
            drawSelection(),
            bracketMatching(),
            closeBrackets(),
            indentOnInput(),
            history(),
            autocompletion(),
            javascript(),
            oneDark,
            EditorView.editable.of(!props.readonly),
            EditorState.readOnly.of(props.readonly),
            keymap.of([
                ...closeBracketsKeymap,
                ...defaultKeymap,
                ...historyKeymap,
                ...foldKeymap,
                ...completionKeymap,
                indentWithTab,
            ]),
            EditorView.updateListener.of((update) => {
                if (update.docChanged) {
                    emit('update:modelValue', update.state.doc.toString())
                }
            }),
            EditorView.theme({
                '&': {
                    fontSize: '12.5px',
                    backgroundColor: '#02080f',
                    border: '1px solid color-mix(in srgb, var(--color-outline-variant) 40%, transparent)',
                    borderRadius: 'var(--radius-DEFAULT, 8px)',
                },
                '.cm-scroller': {
                    fontFamily: 'var(--font-code-md), ui-monospace, SFMono-Regular, Menlo, monospace',
                    minHeight: props.minHeight,
                    maxHeight: props.maxHeight,
                },
                '.cm-gutters': {
                    backgroundColor: 'transparent',
                    borderRight: '1px solid color-mix(in srgb, var(--color-outline-variant) 30%, transparent)',
                },
                '.cm-activeLineGutter, .cm-activeLine': {
                    backgroundColor: 'color-mix(in srgb, var(--color-secondary) 8%, transparent)',
                },
            }),
        ],
    })
}

onMounted(() => {
    if (!host.value) return
    view = new EditorView({ state: buildState(props.modelValue ?? ''), parent: host.value })
})

watch(
    () => props.modelValue,
    (next) => {
        if (!view) return
        const current = view.state.doc.toString()
        if (current === next) return
        view.dispatch({ changes: { from: 0, to: current.length, insert: next ?? '' } })
    },
)

watch(
    () => props.readonly,
    () => {
        if (!view) return
        view.setState(buildState(view.state.doc.toString()))
    },
)

onBeforeUnmount(() => {
    view?.destroy()
    view = null
})
</script>

<template>
    <div ref="host" class="code-editor" />
</template>

<style scoped>
.code-editor {
    width: 100%;
}
.code-editor :deep(.cm-editor) {
    outline: none;
}
.code-editor :deep(.cm-editor.cm-focused) {
    outline: 2px solid color-mix(in srgb, var(--color-secondary) 60%, transparent);
    outline-offset: -1px;
}
</style>
