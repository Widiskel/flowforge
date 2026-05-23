<script setup lang="ts">
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
</script>

<template>
    <section class="builder-shell">
        <aside class="builder-sidebar">
            <div class="sidebar-head">
                <div>
                    <p class="eyebrow">Workflow library</p>
                    <h2>Flows</h2>
                </div>
                <button class="ghost-button compact" type="button">
                    Refresh
                </button>
            </div>

            <div class="workflow-list">
                <div class="empty-state compact">
                    Workflows will appear here after API integration.
                </div>
            </div>

            <div class="scenario-panel">
                <p class="eyebrow">Demo scenarios</p>
                <div class="scenario-stack">
                    <div class="scenario-item"><strong>—</strong><span>active workflows</span></div>
                    <div class="scenario-item"><strong>—</strong><span>nodes in selected flow</span></div>
                    <div class="scenario-item"><strong>Admin / Editor</strong><span>can trigger runs</span></div>
                    <div class="scenario-item"><strong>Viewer</strong><span>read-only RBAC check</span></div>
                </div>
            </div>
        </aside>

        <section class="builder-workspace">
            <div class="workspace-toolbar">
                <div class="workspace-title">
                    <p class="eyebrow">Workflow builder</p>
                    <h2>No workflow selected</h2>
                    <p>Pilih workflow untuk melihat DAG eksekusi.</p>
                </div>
                <div class="workspace-actions">
                    <span class="stream-chip"><span />SSE idle</span>
                    <button type="button" disabled class="primary-button">
                        Trigger run
                    </button>
                </div>
            </div>

            <div class="canvas-stage">
                <div class="canvas-ruler top-ruler">
                    <span>Input</span><span>Decision</span><span>Action</span>
                </div>
                <div class="empty-state">Vue Flow canvas will render here.</div>
            </div>

            <div class="telemetry-dock">
                <div><strong>—</strong><span>active runs</span></div>
                <div><strong>—</strong><span>success rate</span></div>
                <div><strong>—</strong><span>failure rate</span></div>
                <div><strong>—</strong><span>avg duration</span></div>
                <button class="ghost-button compact" type="button">Refresh metrics</button>
            </div>
        </section>

        <aside class="builder-inspector">
            <div class="inspector-card summary-card">
                <p class="eyebrow">Run state</p>
                <div class="summary-grid">
                    <div><strong>—</strong><span>runs</span></div>
                    <div><strong>—</strong><span>success</span></div>
                    <div><strong>—</strong><span>failed</span></div>
                </div>
                <div class="sse-card">
                    <span class="sse-card__pulse" />
                    <p>Stream</p>
                    <strong>idle</strong>
                    <small>Live run snapshots stream here while the workflow executes.</small>
                </div>
            </div>

            <div class="inspector-card">
                <div class="inspector-head">
                    <div>
                        <p class="eyebrow">Selected run</p>
                        <h3>Inspector</h3>
                    </div>
                </div>
                <p class="empty-state compact">Trigger atau pilih run untuk melihat run inspector.</p>
            </div>
        </aside>

        <section class="run-timeline">
            <div class="timeline-head">
                <div>
                    <p class="eyebrow">Run timeline</p>
                    <h3>Recent executions</h3>
                </div>
                <small>Latest run first · tenant scoped</small>
            </div>
            <div class="timeline-list">
                <div class="empty-state compact">No runs yet.</div>
            </div>
        </section>
    </section>
</template>
