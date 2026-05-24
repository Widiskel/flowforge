<script setup lang="ts">
import { computed } from 'vue'
import Icon from '@/components/ui/Icon.vue'
import Button from '@/components/ui/Button.vue'
import CodeEditor from '@/components/ui/CodeEditor.vue'
import type { BuilderStep } from './_shared'

const props = defineProps<{ step: BuilderStep }>()

const SCRIPT_TEMPLATE = `// Available globals:
//   $doc.input    upstream context, keyed by step id
//   $doc.config   this step's config
//   $doc.output   write the step output here, or use return
//   fetch(url, opts), URL, URLSearchParams, console.log/.warn/.error
//
// Example: shape an output from an upstream HTTP step.
const user = $doc.input.fetch_user?.json ?? {};
return {
    userId: user.id,
    userName: user.name,
    summary: \`User \${user.id} loaded\`,
};
`

const scriptValue = computed({
    get: () => {
        const raw = props.step.config.script
        return typeof raw === 'string' ? raw : ''
    },
    set: (value: string) => {
        if (value === '') {
            delete props.step.config.script
            return
        }
        props.step.config.script = value
    },
})

function fillTemplate() {
    if (scriptValue.value.trim() !== '') return
    scriptValue.value = SCRIPT_TEMPLATE
}
</script>

<template>
    <div class="flex flex-col gap-md">
        <div class="rounded-DEFAULT bg-secondary/[0.05] border border-secondary/30 p-sm flex items-start gap-sm">
            <Icon name="security" :size="18" class="text-secondary shrink-0 mt-0.5" />
            <div>
                <p class="text-body-sm font-bold text-on-surface m-0">Sandboxed JavaScript</p>
                <p class="text-body-sm text-on-surface-variant m-0">
                    Runs in a Node 18 child process with no filesystem, child_process, or low-level
                    network modules. <code class="font-code-sm">fetch</code> is available so you can
                    call other APIs; <code class="font-code-sm">console.log</code> output gets
                    captured into the step's <code class="font-code-sm">logs</code>.
                </p>
            </div>
        </div>

        <div class="flex flex-col gap-sm">
            <div class="flex items-start justify-between gap-sm">
                <span class="text-label-caps font-label-caps text-on-surface-variant uppercase">Inline script</span>
                <Button size="sm" variant="ghost" leading-icon="auto_awesome" @click="fillTemplate">Insert template</Button>
            </div>

            <CodeEditor
                v-model="scriptValue"
                :placeholder="SCRIPT_TEMPLATE"
                min-height="240px"
                max-height="440px"
            />
        </div>

        <div class="rounded-DEFAULT border border-outline-variant/40 bg-surface-container-low p-sm flex flex-col gap-1">
            <p class="text-label-caps font-label-caps text-on-surface-variant uppercase m-0">Predefined globals</p>
            <ul class="m-0 pl-md text-body-sm text-on-surface-variant flex flex-col gap-0.5">
                <li><code class="font-code-sm text-on-surface">$doc.input</code> — context output of upstream nodes, keyed by step id.</li>
                <li><code class="font-code-sm text-on-surface">$doc.config</code> — this step's own config (sans <code class="font-code-sm">script</code>).</li>
                <li><code class="font-code-sm text-on-surface">$doc.output</code> — write here, or <code class="font-code-sm">return</code> a value from the script.</li>
                <li><code class="font-code-sm text-on-surface">fetch</code>, <code class="font-code-sm text-on-surface">URL</code>, <code class="font-code-sm text-on-surface">URLSearchParams</code> — outbound HTTP via Node 18 fetch.</li>
                <li><code class="font-code-sm text-on-surface">console.log/.warn/.error</code> — captured into <code class="font-code-sm">{{ step.id }}.output.logs</code>.</li>
            </ul>
        </div>

        <p class="text-body-sm text-on-surface-variant m-0">
            Hard limits: 8s wall-clock, 16 KB script length. Scripts that produce no stdout, return non-JSON,
            or throw fail the step with the captured error message.
        </p>
    </div>
</template>
