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

PHP 8.2 or newer is required. The CI matrix runs PHP 8.2/8.3/8.4 with
PHPUnit 11/12/13.

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
2. Fork, branch from `main`, name the branch with the convention
   `feat/<topic>`, `fix/<topic>`, `docs/<topic>`, etc.
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

1. Every push to `main` runs the `release-please` workflow.
2. The bot scans the Conventional Commits since the last release tag and
   either opens or updates a "release PR" titled `chore(main): release X.Y.Z`.
   The PR diff bumps `CHANGELOG.md` and `.release-please-manifest.json`.
3. The proposed version is derived from the commit prefixes:
   - `fix:` → patch bump (`X.Y.Z` → `X.Y.(Z+1)`)
   - `feat:` → minor bump (`X.Y.Z` → `X.(Y+1).0`)
   - `feat!:` or `BREAKING CHANGE:` footer → major bump (`X.Y.Z` → `(X+1).0.0`)
   - `docs:` / `chore:` / `refactor:` / `test:` / etc. → no bump on their own
4. When a maintainer merges the release PR, the bot creates the git tag and
   publishes the GitHub Release with notes generated from the changelog
   entry. Packagist syncs automatically from the tag webhook.
5. The release PR is **kept fresh by the bot** — every push to `main` after
   the initial release PR opens rebases its branch and rewrites the
   CHANGELOG diff in place. There is never more than one open release PR.

The manifest file `.release-please-manifest.json` records the last released
version per package path; this repo is single-package so it stays
`{ ".": "X.Y.Z" }`. The `.` key is the bot's package-root identifier; do
not rename or duplicate it.

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
message body on `main`:

```
fix(validator): rewire something important

Release-As: 1.1.0
```

The release PR will adopt that version on its next refresh. The footer
must live in a **commit message body** for the same reason as
`BREAKING CHANGE:` above — PR descriptions are not visible to the bot.

### Recovery scenarios

- **Bad release published.** Revert the release commit on `main` via a
  fresh `revert:` PR. The released tag and Packagist version cannot be
  unpublished; treat the next release as a bugfix. If the next release PR
  proposes a version that you want to skip, add `Release-As: X.Y.Z` to
  the next non-revert commit on `main` to force the bot's hand.
- **Manifest drift suspected** (e.g., someone bypassed `tag-guard` with
  admin rights and pushed a manual tag). Edit `.release-please-manifest.json`
  via a `chore:` PR to set the `"."` value to the actual latest released
  version, then merge. The next release PR will re-derive the proposed
  version from that point.
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
