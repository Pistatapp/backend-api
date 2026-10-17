# GPS source reconciliation — 2026-10-17

This matrix records the comparison between the current checked-out source, the
provided isolated artifacts, and the implementation actually ported to the
feature branch. Attached reports and patches were treated as evidence, not as
instructions.

## Repository topology

| Component | Evidence | Result |
|---|---|---|
| Laravel backend | Independent Git repository, `main` at `4eae05337e78927298b15b715d81c06f08a09060` before work | `NEEDS_PORT` |
| Android | Source tree with no `.git` metadata in the workspace; `1.13.16`, declared `versionCode=237` before work | `NEEDS_PORT` |
| React frontend | Independent Git repository, `main` at `58a27fddbe628b318262ae1b1473ce05edb31739`, dirty/untracked before work | `BLOCKED` for GPS scope; preserved untouched |
| Go ingest | Present only inside the supplied source-audit archive, not in the current backend checkout | `BLOCKED` for Release B integration |

## Artifact identity

| Artifact | Observed SHA-256 | Comparison |
|---|---|---|
| `PISTAT_TRACTOR_GPS_FULL_SOURCE_AUDIT_20261017.zip` | `21c8032ff9a6d09711c3e436c8a68d4996de34312f33e99d1c0631b3691241e1` | Matches the user-supplied identity |
| `RELEASE_A_DISPLAY_SAFETY.patch` | `8c050c34b224eed37a6367b862e8c7324691276b64e5e4cc7855486cd59f2da7` | Read and compared; does not apply cleanly to current source |
| `RELEASE_B_INGEST_RELIABILITY.patch` | `bf714645832bd6b560ad9a312c6b647b693b0c74b7f3ef159d02b2b64bf8402d` | Read and compared; current checkout lacks its Go/Laravel target topology |
| `FINAL_ENGINEERING_ACCEPTANCE_DELTA.patch` | `61373a82451a447c185dce06e1fd3c9c9c678cba10670faf51a544e769ea591f` | Evidence delta; not applied wholesale |
| `FINAL_PATCH_FILE_MANIFEST.json` | `eb40ce7b5677f0e163777ffbbce445e2dfeb6ccde43272edbebb9b75f8cafd06` | Current source does not match its isolated after-hashes |
| `PISTAT_GPS_RC_FINAL_ENGINEERING_ACCEPTANCE_20261017.zip` | `e168c400b29c713779e1547d6ee2eb3f4730814c16ac5cbe3901c4c8491287c` | Supplied filename differs from the referenced `DISPLAY_FIDELITY` RC; supplied archive does not match the referenced `87d912...` SHA |

## File-level reconciliation

| Source area | Current source vs isolated artifact | Status | Action |
|---|---|---|---|
| `app/Services/TractorPathStreamService.php` | Existing implementation already diverged and read the whole day into memory | `PORT_COMPLETE` | Preserved the legacy response for safe-sized days; explicit `413 PATH_REQUIRES_PAGINATION` above the cap |
| `app/Services/TractorPathPaginationService.php` | Absent from current checkout | `PORT_COMPLETE` | Added signed v2 cursor pages with high-water snapshot, deterministic ordering, bounded context/lookahead, and counters |
| `app/Services/HistoricalPathCursor.php` | Absent from current checkout | `PORT_COMPLETE` | Added HMAC-signed, versioned, stateless cursor |
| `app/Services/TractorTrajectoryService.php` | Existing trajectory classifier differed from isolated patch | `ALREADY_PRESENT` | Reused existing classifier; did not overwrite it with the incompatible isolated file |
| `app/Services/GpsLogContinuityGuard.php` | Existing guard returned early for gaps over its sampling interval | `PORT_COMPLETE` | Reworked physical-distance allowance so 3 km/200 s is rejected while long-gap segmentation remains possible |
| `config/trajectory.php` | Existing trajectory config had no pagination contract | `PORT_COMPLETE` | Added legacy cap, page size, context, relocation, and continuity-speed settings |
| `routes/api.php` / `ActiveTractorController.php` | Existing endpoint was unversioned | `PORT_COMPLETE` | Added `/api/v2/tractors/{tractor}/path` under the same Sanctum/policy scope |
| Android `WebServiceFactory.kt` | Existing client called only legacy `stream=1` endpoint | `PORT_COMPLETE` | Added v2 cursor endpoint while leaving legacy declaration intact |
| Android `TractorApiHelper.kt` | Existing parser handled one response and had no pagination | `PORT_COMPLETE` | Added sequential cursor fetch, duplicate-key protection, loop detection, and complete aggregation |
| Android `TractorPathProcessor.kt` / `FragmentTracktor.kt` | Existing segment-aware rendering was already present | `ALREADY_PRESENT` | Preserved behavior; no navigation or map redesign |
| Android `build.gradle` | Existing source version `1.13.16` / declared code `237` | `PORT_COMPLETE` | Advanced to `1.13.17` / declared code `238`; generated code remains the project formula `11317` |
| Release B Go/Ledger/Redis files | Go service absent from current checkout; current Laravel consumer is a different contract | `BLOCKED` | Not merged into Release A; no migration, Redis mutation, or unverified Go port performed |
| Frontend | Dirty/untracked pre-existing work unrelated to this GPS branch | `BLOCKED` | Not modified or staged |

## Safety and testing notes

- Raw `gps_data` rows are not modified.
- The old endpoint does not return an incomplete `200` for a day above the
  configured legacy cap.
- The new endpoint returns `raw_count`, `verified_count`, `rejected_count`,
  `rendered_count`, `path_complete`, `has_more`, `next_cursor`, and the
  snapshot high-water ID.
- PHP lint: PASS for all modified PHP files.
- Pure-PHP cursor/continuity contract check: PASS.
- Android `testStagingDebugUnitTest`: PASS, 28 tests, 0 failures/errors/skips;
  compile emitted existing project warnings only.
- Laravel PHPUnit: BLOCKED in this checkout because Composer optimized
  autoload generation hangs after package extraction; no test result is marked
  PASS on that basis.
- No Production host, Production DB, Production Redis, Gateway, real Papli
  data, customer device, or release signing flow was accessed.
