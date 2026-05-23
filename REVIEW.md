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
