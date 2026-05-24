<?php

declare(strict_types=1);

namespace App\GraphQL;

use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use App\Models\WorkflowVersion;
use GraphQL\Error\UserError;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Schema as GraphQLSchema;

/**
 * Tiny tenant-scoped GraphQL surface. Authentication relies on the same JWT
 * middleware as REST; resolvers re-check tenant ownership.
 *
 * This is a deliberately compact bonus surface. Full coverage is REST first.
 */
class Schema
{
    private static ?GraphQLSchema $schema = null;

    public static function build(): GraphQLSchema
    {
        if (self::$schema !== null) {
            return self::$schema;
        }

        $stepType = new ObjectType([
            'name' => 'WorkflowStep',
            'fields' => [
                'id' => Type::nonNull(Type::string()),
                'name' => Type::nonNull(Type::string()),
                'type' => Type::nonNull(Type::string()),
                'dependsOn' => Type::listOf(Type::string()),
            ],
        ]);

        $versionType = new ObjectType([
            'name' => 'WorkflowVersion',
            'fields' => fn () => [
                'id' => Type::nonNull(Type::string()),
                'versionNumber' => [
                    'type' => Type::nonNull(Type::int()),
                    'resolve' => fn (WorkflowVersion $v) => $v->version_number,
                ],
                'changeSummary' => [
                    'type' => Type::string(),
                    'resolve' => fn (WorkflowVersion $v) => $v->change_summary,
                ],
                'createdAt' => [
                    'type' => Type::string(),
                    'resolve' => fn (WorkflowVersion $v) => optional($v->created_at)?->toIso8601String(),
                ],
                'steps' => [
                    'type' => Type::listOf($stepType),
                    'resolve' => function (WorkflowVersion $v) {
                        return collect($v->definition['steps'] ?? [])
                            ->map(fn ($s) => (object) [
                                'id' => $s['id'] ?? '',
                                'name' => $s['name'] ?? '',
                                'type' => $s['type'] ?? '',
                                'dependsOn' => $s['dependsOn'] ?? [],
                            ])
                            ->all();
                    },
                ],
            ],
        ]);

        $workflowType = new ObjectType([
            'name' => 'Workflow',
            'fields' => fn () => [
                'id' => Type::nonNull(Type::string()),
                'name' => Type::nonNull(Type::string()),
                'description' => Type::string(),
                'status' => Type::nonNull(Type::string()),
                'createdAt' => [
                    'type' => Type::string(),
                    'resolve' => fn (Workflow $w) => optional($w->created_at)?->toIso8601String(),
                ],
                'updatedAt' => [
                    'type' => Type::string(),
                    'resolve' => fn (Workflow $w) => optional($w->updated_at)?->toIso8601String(),
                ],
                'currentVersion' => [
                    'type' => $versionType,
                    'resolve' => fn (Workflow $w) => $w->currentVersion,
                ],
            ],
        ]);

        $stepRunType = new ObjectType([
            'name' => 'StepRun',
            'fields' => [
                'id' => Type::nonNull(Type::string()),
                'stepId' => [
                    'type' => Type::nonNull(Type::string()),
                    'resolve' => fn ($s) => $s->step_id,
                ],
                'stepType' => [
                    'type' => Type::nonNull(Type::string()),
                    'resolve' => fn ($s) => $s->step_type,
                ],
                'status' => [
                    'type' => Type::nonNull(Type::string()),
                    'resolve' => fn ($s) => is_object($s->status) ? $s->status->value : (string) $s->status,
                ],
                'attemptCount' => [
                    'type' => Type::nonNull(Type::int()),
                    'resolve' => fn ($s) => (int) $s->attempt_count,
                ],
                'durationMs' => [
                    'type' => Type::int(),
                    'resolve' => fn ($s) => $s->duration_ms,
                ],
                'errorMessage' => [
                    'type' => Type::string(),
                    'resolve' => fn ($s) => $s->error_message,
                ],
            ],
        ]);

        $runType = new ObjectType([
            'name' => 'WorkflowRun',
            'fields' => fn () => [
                'id' => Type::nonNull(Type::string()),
                'workflowId' => [
                    'type' => Type::nonNull(Type::string()),
                    'resolve' => fn (WorkflowRun $r) => $r->workflow_id,
                ],
                'status' => [
                    'type' => Type::nonNull(Type::string()),
                    'resolve' => fn (WorkflowRun $r) => is_object($r->status) ? $r->status->value : (string) $r->status,
                ],
                'durationMs' => [
                    'type' => Type::int(),
                    'resolve' => fn (WorkflowRun $r) => $r->duration_ms,
                ],
                'startedAt' => [
                    'type' => Type::string(),
                    'resolve' => fn (WorkflowRun $r) => optional($r->started_at)?->toIso8601String(),
                ],
                'finishedAt' => [
                    'type' => Type::string(),
                    'resolve' => fn (WorkflowRun $r) => optional($r->finished_at)?->toIso8601String(),
                ],
                'createdAt' => [
                    'type' => Type::string(),
                    'resolve' => fn (WorkflowRun $r) => optional($r->created_at)?->toIso8601String(),
                ],
                'stepRuns' => [
                    'type' => Type::listOf($stepRunType),
                    'resolve' => fn (WorkflowRun $r) => $r->stepRuns,
                ],
            ],
        ]);

        $tenantType = new ObjectType([
            'name' => 'Tenant',
            'fields' => [
                'id' => Type::nonNull(Type::string()),
                'name' => Type::nonNull(Type::string()),
                'slug' => Type::nonNull(Type::string()),
            ],
        ]);

        $userType = new ObjectType([
            'name' => 'User',
            'fields' => fn () => [
                'id' => Type::nonNull(Type::string()),
                'name' => Type::nonNull(Type::string()),
                'email' => Type::nonNull(Type::string()),
                'role' => Type::nonNull(Type::string()),
                'tenant' => [
                    'type' => $tenantType,
                    'resolve' => fn (User $u) => $u->tenant,
                ],
            ],
        ]);

        $queryType = new ObjectType([
            'name' => 'Query',
            'fields' => [
                'me' => [
                    'type' => $userType,
                    'resolve' => function ($root, array $args, Context $ctx) {
                        return $ctx->user;
                    },
                ],
                'workflows' => [
                    'type' => Type::listOf($workflowType),
                    'args' => [
                        'limit' => ['type' => Type::int(), 'defaultValue' => 25],
                        'status' => ['type' => Type::string()],
                    ],
                    'resolve' => function ($root, array $args, Context $ctx) {
                        $limit = max(1, min(100, (int) ($args['limit'] ?? 25)));
                        $query = Workflow::query()
                            ->where('tenant_id', $ctx->user->tenant_id)
                            ->with('currentVersion')
                            ->latest();
                        if (! empty($args['status'])) {
                            $allowed = ['draft', 'active', 'archived'];
                            if (! in_array($args['status'], $allowed, true)) {
                                throw new UserError('Unsupported status filter.');
                            }
                            $query->where('status', $args['status']);
                        }

                        return $query->limit($limit)->get();
                    },
                ],
                'workflow' => [
                    'type' => $workflowType,
                    'args' => ['id' => Type::nonNull(Type::string())],
                    'resolve' => function ($root, array $args, Context $ctx) {
                        return Workflow::query()
                            ->where('tenant_id', $ctx->user->tenant_id)
                            ->with(['currentVersion', 'versions'])
                            ->find($args['id']);
                    },
                ],
                'workflowRuns' => [
                    'type' => Type::listOf($runType),
                    'args' => [
                        'workflowId' => ['type' => Type::string()],
                        'status' => ['type' => Type::string()],
                        'limit' => ['type' => Type::int(), 'defaultValue' => 25],
                    ],
                    'resolve' => function ($root, array $args, Context $ctx) {
                        $limit = max(1, min(100, (int) ($args['limit'] ?? 25)));
                        $query = WorkflowRun::query()
                            ->where('tenant_id', $ctx->user->tenant_id)
                            ->latest();
                        if (! empty($args['workflowId'])) {
                            $query->where('workflow_id', $args['workflowId']);
                        }
                        if (! empty($args['status'])) {
                            $allowed = ['PENDING', 'RUNNING', 'SUCCESS', 'FAILED', 'TIMEOUT', 'CANCELLED'];
                            if (! in_array($args['status'], $allowed, true)) {
                                throw new UserError('Unsupported run status filter.');
                            }
                            $query->where('status', $args['status']);
                        }

                        return $query->limit($limit)->get();
                    },
                ],
                'workflowRun' => [
                    'type' => $runType,
                    'args' => ['id' => Type::nonNull(Type::string())],
                    'resolve' => function ($root, array $args, Context $ctx) {
                        return WorkflowRun::query()
                            ->where('tenant_id', $ctx->user->tenant_id)
                            ->with('stepRuns')
                            ->find($args['id']);
                    },
                ],
            ],
        ]);

        self::$schema = new GraphQLSchema(['query' => $queryType]);

        return self::$schema;
    }
}
