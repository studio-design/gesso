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

| Command                       | What it does                                       |
| ----------------------------- | -------------------------------------------------- |
| `composer test`               | Run the full test suite (PHPUnit)                  |
| `composer stan`               | PHPStan level 6 over `src/` and `tests/`           |
| `composer cs-check`           | PHP-CS-Fixer dry-run                               |
| `composer cs`                 | PHP-CS-Fixer apply (rewrites files)                |
| `composer ci`                 | All of the above, in CI order                      |
| `npm run docs:build`          | Build the documentation site (VitePress)           |
| `npm run docs:anchor-probe`   | List the built heading anchors for `docs:links`    |
| `npm run docs:links`          | Check documentation links (lychee)                 |

Heading links have to resolve on two surfaces: GitHub renders these Markdown
files directly, and VitePress publishes them. VitePress's own slugifier
disagrees with GitHub's for em dashes, underscores, leading digits, and
`--flags`, so `docs/.vitepress/github-slug.mjs` overrides it. Write `#fragment`
links the way GitHub spells them; `docs:links` checks them.

Run `docs:build` and `docs:anchor-probe` before `docs:links` to also check the
published anchors — the probe points every built anchor back at the heading it
came from, so lychee, rather than a second copy of GitHub's rules, decides
whether the two agree. On a clean checkout `docs:links` runs on its own and
checks every link, just not the site's ids: it takes the probe as the glob
`docs/.vitepress/dist/*.md`, which lychee skips with a warning when nothing
matches. Naming the file directly would abort the whole run instead, so keep it
a glob.

`docs:build` also mirrors `docs/` into `docs/.vitepress/dist/` so every page is
served as raw Markdown at `<page>.md`, and writes `llms-full.txt` from the
pages listed in `docs/public/llms.txt`. That index is hand-written; the build
fails when its links no longer match the sidebar and nav in
`docs/.vitepress/config.mts`, naming each missing or unexpected URL. Add a
sidebar page and its `llms.txt` line together.

`docs:links` needs [lychee](https://github.com/lycheeverse/lychee) on your
`PATH` — it is a standalone binary rather than an npm package, so it is not
installed by `npm ci`:

```sh
brew install lychee     # or: cargo install lychee
```

CI runs the same `lychee.toml` through `lycheeverse/lychee-action`, split in
two. The `Documentation site` job checks repository files and heading anchors
offline on every PR. The weekly `Scheduled checks` workflow checks external
URLs and opens an issue when one breaks, so another host's outage does not fail
your PR. Skipping the install is fine; CI will catch a broken link.

## Workflow

1. Open an issue describing the problem before starting non-trivial work.
   For bug fixes that come with a failing test, jumping straight to a PR
   is fine.
2. Fork and choose the correct base branch: use `main` for normal work, `2.x`
   for a v2-only fix, and `1.x` for a v1-only fix. Name the branch with the
   convention `feat/<topic>`, `fix/<topic>`, `docs/<topic>`, etc.
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
contract for what each version bump promises is in
[Versioning and support policy](docs/versioning.md#whats-covered-by-semver);
this section covers the mechanical flow.

How it works:

1. Every push to a configured target branch runs the `release-please` workflow.
   This is `main` for v3, `2.x` for v2 maintenance, and `1.x` for v1
   maintenance.
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
5. Each release PR is **kept fresh by the bot** — a push to its target branch
   that changes the changelog rebases the bot-managed PR branch and rewrites
   the CHANGELOG diff in place. There is at most one open release PR per
   target branch, so `main`, `2.x`, and `1.x` may each have one open at the
   same time.

Two things keep a release PR from merging, and neither fixes itself:

- **It falls behind its target branch.** The bot rewrites the PR only when the
  changelog changes. A merge that adds no entry (a `docs:` commit, for
  example) leaves the PR one commit behind, and the branch rulesets require
  an up-to-date branch. Rebase the bot branch onto the target branch and push
  it with `--force-with-lease`. The diff must still be `CHANGELOG.md` and
  `.release-please-manifest.json` only.
- **Its checks wait for approval.** Workflow runs started by the bot's push
  stop at `action_required`. A maintainer approves or re-runs them on the
  Actions page. A maintainer's own push to the bot branch, such as the rebase
  above, starts them without approval.

The manifest file `.release-please-manifest.json` records the last released
version per package path on each target branch; this repo is single-package so
it stays `{ ".": "X.Y.Z" }`. The value differs per branch.
The `.` key is the bot's package-root identifier; do not rename or duplicate it.

### Gesso 3.0 beta and stable promotion

`main` publishes 3.0 as betas first, so the published artifact can be tested
before the stable compatibility promise. `2.x` and `1.x` keep their normal
stable flow from their own release configuration. The gates for the stable
release are in
[ADR 0007](docs/adr/0007-v3-scope-and-release-window.md#release-window).

1. The `main` configuration uses `versioning: prerelease`,
   `prerelease-type: beta`, `prerelease: true`, and, for the first beta only,
   `release-as: 3.0.0-beta.1`. The repository discards commit bodies when
   squash-merging, so a `Release-As` commit footer is not a safe source for the
   first beta version.
2. Before merging the release PR, verify that it proposes the expected beta
   version, changes only release-please-owned files, and has a green supported
   matrix. Every removal it ships needs its deprecation in a **tagged** v2
   release: when one is still waiting on `2.x`, merge the `2.x` release PR
   first.
3. Merge the release PR. Verify all four records agree: the `v3.0.0-beta.N`
   git tag, the GitHub Release marked **Pre-release**, the manifest value, and
   the Packagist version/source reference. After `3.0.0-beta.1`, immediately
   remove `release-as` from the configuration through a normal PR before
   merging another releasable change; otherwise release-please keeps forcing
   the already published version.
4. Test the Packagist artifact in a clean project with
   `composer require --dev "studio-design/gesso:^3.0@beta" phpunit/phpunit`.
   Run the repository examples and at least one downstream suite against the
   published package rather than a path repository. Ship any corrections as
   normal PRs and publish another beta through release-please when necessary.
5. To promote the accepted beta, open a normal PR that changes `prerelease` to
   `false`, temporarily sets `release-as: 3.0.0`, and adds the stable-only
   `changelog-sections` that keep the promotion commit visible. Keep
   `versioning: prerelease` so release-please promotes the beta line instead
   of calculating an unrelated bump. Keep the `@beta` installation commands
   and the README beta notice: the stable tag exists only once the release PR
   is merged. After merge, verify that the refreshed release PR proposes
   stable `3.0.0`, merge it, and repeat the checks from steps 3 and 4.
6. Once the stable package is installable, open a follow-up PR that removes
   `release-as` and the stable-only `changelog-sections`, drops `@beta` from
   the installation commands, and removes the README beta notice. Move the
   documentation site to `main` in the same step: `docs.yml` deploys from
   `2.x` until 3.0.0, so edit it on both branches until only `main` deploys,
   and allow `main` in the `github-pages` environment's deployment branches.

If downstream evaluation finds a release-blocking defect after step 5 and
before the stable release PR is merged, do not merge that release PR. First
merge a normal PR that restores `prerelease: true` and removes `release-as`
and the stable-only `changelog-sections`. Wait for release-please to refresh
the proposal to the next beta, then repeat steps 3 and 4.

Do not merge a stable `3.0.0` release PR while `prerelease` is `true`, or
before a published beta has passed the clean-consumer checks.

### Maintenance branches and backports

`main` is the v3 development branch, `2.x` the v2 maintenance branch, and `1.x`
the v1 maintenance branch. The support windows and accepted change classes are
defined in `docs/versioning.md`, under the
[v2](docs/versioning.md#v2-maintenance-lifecycle) and
[v1](docs/versioning.md#v1-maintenance-lifecycle) maintenance lifecycles.

For a fix shared by more than one major:

1. Merge and verify the fix on `main`. If the code no longer exists on `main`,
   start from `2.x` (a v2-only fix).
2. Create a branch from the latest `2.x` and cherry-pick the squashed `main`
   commit. Resolve only differences required by the v2 codebase.
3. Open a separate PR targeting `2.x`, using the same Conventional Commit title
   when it still describes the backport.
4. If v1 is affected and still inside its window, create a branch from the
   latest `1.x`, cherry-pick the squashed `2.x` commit, and open a PR
   targeting `1.x`.
5. Run the normal checks on each PR. Do not merge `main` into `2.x` or `2.x`
   into `1.x`, and do not group unrelated backports in one PR.

release-please runs independently for pushes to `main`, `2.x`, and `1.x`; merge
the release PR for the branch whose line you intend to publish. Tags and
`CHANGELOG.md` remain release-please-owned on every branch.

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
