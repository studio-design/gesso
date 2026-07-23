# Contributing

Thanks for considering a contribution. This is a small library with a
single maintainer; the workflow below is optimised for quick iteration.

## Quick start

```bash
git clone https://github.com/studio-design/gesso
cd gesso
composer install
composer ci   # runs cs-check + stan + test
```

PHP 8.3 or newer is required. The CI matrix runs PHP 8.3/8.4/8.5 with
PHPUnit 12/13.

## Local checks

| Command                | What it does                                  |
| ---------------------- | --------------------------------------------- |
| `composer test`        | Run the full test suite (PHPUnit)             |
| `composer stan`        | PHPStan level 6 over `src/` and `tests/`      |
| `composer cs-check`    | PHP-CS-Fixer dry-run                          |
| `composer cs`          | PHP-CS-Fixer apply (rewrites files)           |
| `composer ci`          | All of the above, in CI order                 |

## Workflow

1. Open an issue describing the problem before starting non-trivial work.
   For bug fixes that come with a failing test, jumping straight to a PR
   is fine.
2. Fork and choose the correct base branch: use `main` for normal work; after
   the `1.x` maintenance branch exists, use `1.x` for a v1-only fix. Name the
   branch with the convention `feat/<topic>`, `fix/<topic>`, `docs/<topic>`,
   etc.
3. Write tests first when the change is observable behaviour. The test
   suite uses snake_case method names and PHPUnit `#[Test]` attributes.
4. Run `composer ci` locally before pushing.
5. Open a PR. The title must follow Conventional Commits
   (`feat(scope): …`, `fix(scope): …`, etc.) — a CI check enforces this.
6. The PR template asks for the failing scenario and how the change is
   verified. Skipping it slows review.

## Commits

History is squash-merged; the squash uses the PR title. So:
- The PR title becomes the canonical commit message
- Conventional Commits convention is enforced on PR titles
- Commit titles inside the PR are free-form; aim for readable history but
  don't worry about formatting since they get squashed

## Code style

PER-CS2.0 + `@PHP8x2Migration` via PHP-CS-Fixer (config in
`.php-cs-fixer.dist.php`). Highlights:

- `declare(strict_types=1);` in every PHP file
- Strict comparisons (`===`, `!==`) only
- Explicit `use function` / `use const` imports — no global namespace
  fallback in production code
- Class element order: traits → constants → static properties →
  properties → constructor → public static methods → public → protected
  → private
- Tests use snake_case method names

If `composer cs-check` fails, run `composer cs` and commit the result.

## Adding new public API

1. Mark the class `final` unless you have a concrete extension story
2. Avoid adding public properties — favour readonly DTOs or accessors
3. Methods that are only public for the PHPUnit extension or paratest
   sidecar protocol must carry an `@internal` annotation in the docblock
   with a one-line explanation of why they cannot be `private`
4. Document the behaviour in README before merging

## Releases

Releases are automated by [release-please](https://github.com/googleapis/release-please).
Maintainers do **not** edit `CHANGELOG.md` or push tags by hand. The
contract for what each version bump promises is in the README's
[Versioning and support policy](README.md#versioning-and-support-policy);
this section covers the mechanical flow.

How it works:

1. Every push to a configured target branch runs the `release-please` workflow.
   This is `main` for v2 and, after v1.10.0, `1.x` for v1 maintenance.
2. For the branch that triggered the workflow, the bot scans the Conventional
   Commits since that line's last release and either opens or updates a release
   PR titled `chore(<branch>): release X.Y.Z`. The PR diff bumps that branch's
   `CHANGELOG.md` and `.release-please-manifest.json`.
3. The proposed version is derived from the commit prefixes:
   - `fix:` → patch bump (`X.Y.Z` → `X.Y.(Z+1)`)
   - `feat:` → minor bump (`X.Y.Z` → `X.(Y+1).0`)
   - `feat!:` or `BREAKING CHANGE:` footer → major bump (`X.Y.Z` → `(X+1).0.0`)
   - `docs:` / `chore:` / `refactor:` / `test:` / etc. → no bump on their own
4. When a maintainer merges the release PR, the bot creates the git tag and
   publishes the GitHub Release with notes generated from the changelog
   entry. Packagist syncs automatically from the tag webhook.
5. Each release PR is **kept fresh by the bot** — every push to its target
   branch after the initial release PR opens rebases the bot-managed PR branch
   and rewrites the CHANGELOG diff in place. There is at most one open release
   PR per target branch, so `main` and `1.x` may have two release PRs open at
   the same time.

The manifest file `.release-please-manifest.json` records the last released
version per package path on each target branch; this repo is single-package so
it stays `{ ".": "X.Y.Z" }`. The value may differ between `main` and `1.x`.
The `.` key is the bot's package-root identifier; do not rename or duplicate it.

### Gesso 2.0 beta and stable promotion

The first release of the new `studio-design/gesso` package uses a beta stage so
the published artifact can be tested before the stable compatibility promise.
This is a temporary `main`-branch procedure; the `1.x` branch continues its
normal stable patch flow from its own release configuration.

1. Close any open stable v2 release PR as obsolete. Changing the release
   component from the legacy package name to `gesso` changes release-please's
   bot branch, so it cannot safely update that old PR in place. Never merge or
   manually edit the obsolete release PR.
2. Register `studio-design/gesso` on Packagist from this GitHub repository.
   Confirm that its `dev-main` metadata reports the new package name and source
   repository. Do not mark the old package abandoned yet.
3. Merge the beta-readiness PR. The `main` configuration must use
   `versioning: prerelease`, `prerelease-type: beta`, `prerelease: true`, and
   the temporary `release-as: 2.0.0-beta.1`. The repository discards commit
   bodies when squash-merging, so a `Release-As` commit footer is not a safe
   source for the first beta version.
4. Wait for the bot-managed release PR to refresh. Before merging it, verify
   that it proposes `2.0.0-beta.1`, changes only release-please-owned files,
   and has a green supported matrix.
5. Merge that release PR. Verify all four records agree: the `v2.0.0-beta.1`
   git tag, the GitHub Release marked **Pre-release**, the manifest value, and
   the Packagist version/source reference. Immediately remove `release-as`
   from the configuration through a normal PR before merging another
   releasable change; otherwise release-please will keep forcing the already
   published beta version.
6. Test the Packagist artifact in a clean project with
   `composer require --dev "studio-design/gesso:^2.0@beta"`. Run the repository
   examples and at least one representative downstream migration against the
   published package rather than a path repository. Ship any corrections as
   normal PRs and publish another beta through release-please when necessary.
7. To promote the accepted beta, open a normal PR that changes `prerelease` to
   `false`, temporarily sets `release-as: 2.0.0`, restores the stable-only
   `changelog-sections` that keep the promotion commit visible, and updates the
   corresponding invariant tests. In the same PR, replace the beta installation
   commands with `studio-design/gesso:^2.0` and remove the pre-release evaluation
   warnings; the documentation policy follows the configured `prerelease` mode
   so both the promotion PR and the bot-managed release PR remain green. Keep
   `versioning: prerelease` so release-please promotes the beta line instead of
   calculating an unrelated bump. After merge, verify that the refreshed release
   PR proposes stable `2.0.0` and repeat the tag, GitHub Release, manifest,
   Packagist, clean-install, and example checks before announcing general
   availability. Remove `release-as` through a normal PR immediately after the
   stable release is published.
   If downstream evaluation finds another release-blocking defect before the
   stable release PR is merged, do not merge that release PR. First merge a
   normal PR that restores `prerelease: true`, removes `release-as` and the
   stable-only `changelog-sections`, restores the `^2.0@beta` installation
   commands and pre-release evaluation warnings, and updates the invariant
   tests. Wait for release-please to refresh the proposal to the next beta. For
   that beta, perform the tag, GitHub Release, manifest, and Packagist checks
   from step 5, then the published-artifact and downstream validation from
   step 6. The beta.1-specific `release-as` cleanup in step 5 does not apply
   because this recovery PR already removed it.
8. Only after the stable package is installable and verified, mark
   `studio-design/openapi-contract-testing` abandoned on Packagist with
   `studio-design/gesso` as its suggested replacement. Do not delete its tags
   or releases; v1 remains supported through its documented lifecycle.

Do not merge a stable `2.0.0` release PR while `prerelease` is `true`, while the
new Packagist package is unregistered, or before the published beta artifact
has passed the clean-consumer checks.

### Maintenance branches and backports

After v1.10.0 is released, `main` is the v2 development branch and `1.x` is the
v1 maintenance branch. The support windows and accepted change classes are
defined in [`docs/versioning.md`](docs/versioning.md#v1-maintenance-lifecycle).

For a fix shared by both majors:

1. Merge and verify the fix on `main`.
2. Create a branch from the latest `1.x` and cherry-pick the squashed `main`
   commit. Resolve only differences required by the v1 codebase.
3. Open a separate PR targeting `1.x`, using the same Conventional Commit title
   when it still describes the backport.
4. Run the normal checks. Do not merge `main` into `1.x` or group unrelated
   backports in one PR.

A v1-only fix may start directly from `1.x`. release-please runs independently
for pushes to `main` and `1.x`; merge the release PR for the branch whose line
you intend to publish. Tags and `CHANGELOG.md` remain release-please-owned on
both branches.

### Discipline

- **Never push a `v*` tag manually.** The release-please manifest tracks
  the last released version; a hand-tag desyncs it and the next release PR
  proposes a wrong version. The `tag-guard` workflow will fail-loud on any
  non-bot tag push. If you need a hotfix release, land the fix via a
  regular `fix:` PR and let the bot's release PR ship it.
- **Never edit `CHANGELOG.md` manually** in feature / fix PRs. The release
  PR is the only place CHANGELOG should change. If a release entry is
  worded poorly, edit the release PR before merging it.
- **Squash-merge the release PR as-is.** Do not edit the squash subject —
  the bot uses it to detect that the merged commit is a release. Other
  merge strategies (rebase / merge commit) work, but squash matches the
  rest of this repo's history.
- **Squash-merging a `feat!:` PR: confirm the bang survives.** GitHub's
  squash dialog defaults the subject to the PR title but lets the
  maintainer edit it before merging. If the `!` is dropped at squash
  time, release-please reads the squashed subject (no `!`) and proposes
  a minor instead of a major. The PR-title check (`pr-title.yml`) only
  validates the title at PR-time; it cannot catch an edit at merge-time.

### `BREAKING CHANGE:` footer placement

Conventional Commits accepts `BREAKING CHANGE: <text>` as a commit-message
footer to flag a breaking change. release-please reads it from the **squash
commit message body**, which is built from the source-branch commit
messages — **not** from the PR description textbox. So:

- ✅ Put `BREAKING CHANGE: <text>` in an actual commit message body before
  pushing. It will land in the squash body and the bot will see it.
- ❌ Writing it only in the PR description loses it at squash time and
  the bot proposes a wrong (minor) version.

Prefer the `feat!:` prefix when possible — it's harder to lose accidentally
than a body footer.

### Forcing a specific bump

If a `fix:`-only batch should ship as a minor release (e.g., the fix is
behaviourally significant), use a `Release-As: X.Y.Z` footer in a commit
message body on the branch being released:

```
fix(validator): rewire something important

Release-As: 1.1.0
```

The release PR will adopt that version on its next refresh. The footer
must live in a **commit message body** for the same reason as
`BREAKING CHANGE:` above — PR descriptions are not visible to the bot.

### Recovery scenarios

- **Bad release published.** Revert the release commit via a fresh `revert:` PR
  targeting the target branch that produced it. The released tag and Packagist
  version cannot be unpublished; treat the next release on that line as a
  bugfix. If its next release PR proposes a version that you want to skip, add
  `Release-As: X.Y.Z` to the next non-revert commit on the same target branch.
- **Manifest drift suspected** (e.g., someone bypassed `tag-guard` with
  admin rights and pushed a manual tag). Edit `.release-please-manifest.json`
  on the affected target branch via a `chore:` PR to set the `"."` value to
  that line's actual latest released version, then merge. The next release PR
  for that branch will re-derive the proposed version from that point.
- **Bot-authored commits do not trigger downstream Actions** by default
  (GitHub's loop-prevention). The current pipeline is fine because tag
  publication and Packagist sync are both handled by the bot's own action
  invocation and an external webhook respectively. If you ever add a
  workflow that fires on `push: tags: ['v*']` and want it to run for
  bot-published tags, switch the bot's `token:` from `GITHUB_TOKEN` to a
  PAT or GitHub App token.

## Reporting bugs

Use the bug-report issue template. The fastest way to a fix is a
self-contained reproduction, ideally as a failing test against the
current `main`.

## Security issues

See `SECURITY.md`. **Do not file security reports as public issues.**
