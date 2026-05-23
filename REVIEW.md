# Code Review Log

Catatan review per PR yang sudah masuk ke `main`.

## PR #1 — feat(auth): JWT authentication with refresh token rotation

- Branch: `feature/auth-jwt-rbac`
- PR: https://github.com/Widiskel/flowforge/pull/1
- Status: merged

Catatan review:
- `RotateJwtRefreshTokenAction` masih duplikasi logic dengan `JwtRefreshTokenStore::rotate()`. Pilih satu jadi single source supaya ga pecah.
- `AuthController::logout` catch `Throwable` terlalu lebar, lebih aman dipersempit ke `JWTException`.

Tindak lanjut:
- `JwtRefreshTokenStore::rotate()` dihapus. Rotation + reuse-detection cukup di `RotateJwtRefreshTokenAction`.
- `AuthController::logout` sekarang catch `JWTException` saja.

Verifikasi setelah fix: 17 tests pass, pint dan typecheck hijau, build sukses.

Putusan: OK merge.

## PR #2 — feat(workflow): CRUD and versioning

- Branch: `feature/workflow-crud-versioning`
- PR: https://github.com/Widiskel/flowforge/pull/2
- Status: merged

Catatan review:
- `per_page > 100` di `WorkflowController::index` dan `WorkflowVersionController::index` pakai `abort_if(..., 422)`. Endpoint lain pakai FormRequest yang return shape `{message, errors[]}` standar Laravel, jadi yang ini inkonsisten dari sisi API consumer.
- `current_version_id` di-set lewat `forceFill(...)->save()` padahal kolomnya sudah `$fillable`. Misleading, seolah-olah lagi bypass mass assignment guard. Cukup pakai `update(...)`.

Tindak lanjut:
- `per_page` sekarang divalidasi via `validator()` jadi shape error JSON-nya konsisten.
- `forceFill(...)->save()` diganti `update(...)` di Create/Update/Rollback action.

Verifikasi setelah fix: 35 tests pass (105 assertions), pint dan typecheck hijau, build sukses.

Putusan: OK merge.
