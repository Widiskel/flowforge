# FlowForge Helm Chart

Helm chart untuk deployment FlowForge ke Kubernetes.

## Prerequisites

- Kubernetes 1.24+
- Helm 3.9+
- PostgreSQL 14+ (external atau via `bitnami/postgresql`)
- Redis 6+ (external atau via `bitnami/redis`)

## Installation

```bash
# Add repository (opsional)
helm repo add widiskel https://widiskel.github.io/charts
helm repo update

# Install
helm install flowforge ./deploy/helm/flowforge \
  --create-namespace \
  --namespace flowforge \
  --set image.tag=v1.0.0 \
  --set ingress.host=flowforge.your-domain.com
```

## Configuration

| Parameter | Description | Default |
|-----------|-------------|---------|
| `replicaCount` | Number of app replicas | `2` |
| `image.repository` | Container image repository | `ghcr.io/widiskel/flowforge` |
| `image.tag` | Container image tag | `latest` |
| `service.type` | Kubernetes service type | `ClusterIP` |
| `ingress.enabled` | Enable ingress | `true` |
| `ingress.host` | Ingress hostname | `flowforge.example.com` |

## Production notes

- Gunakan external PostgreSQL/Redis untuk production
- Setup persistent volume untuk database
- Gunakan Kubernetes Secrets untuk `APP_KEY`, `JWT_SECRET`, `DB_PASSWORD`
- Enable HPA untuk auto-scaling
- Setup Pod Disruption Budget untuk zero-downtime

## Rollback

```bash
helm rollback flowforge <revision>
```
