# CI Integration

- [GitHub Actions Step Summary](#github-actions-step-summary)
- [Markdown output file](#markdown-output-file)
- [Coverage output formats](#coverage-output-formats)
- [Spec patch coverage gate](#spec-patch-coverage-gate)

## GitHub Actions Step Summary

When running in GitHub Actions, the extension **automatically** detects the `GITHUB_STEP_SUMMARY` environment variable and appends a Markdown coverage report to the job summary. No configuration needed.

> **Note:** Both features are independent — when running in GitHub Actions with `output_file` configured, the Markdown report is written to both the file and the Step Summary.

## Markdown output file

Use the `output_file` parameter to write a Markdown report to a file. This is useful for posting coverage as a PR comment:

```xml
<extensions>
    <bootstrap class="Studio\Gesso\PHPUnit\OpenApiCoverageExtension">
        <parameter name="spec_base_path" value="openapi/bundled"/>
        <parameter name="specs" value="front,admin"/>
        <parameter name="output_file" value="coverage-report.md"/>
    </bootstrap>
</extensions>
```

You can also use the `OPENAPI_CONSOLE_OUTPUT` environment variable in CI to show uncovered endpoints in the job log:

```yaml
- name: Run tests (show uncovered endpoints)
  run: vendor/bin/phpunit
  env:
    OPENAPI_CONSOLE_OUTPUT: uncovered_only
```

Example GitHub Actions workflow step to post the report as a PR comment:

```yaml
- name: Run tests
  run: vendor/bin/phpunit

- name: Post coverage comment
  if: github.event_name == 'pull_request' && hashFiles('coverage-report.md') != ''
  uses: marocchino/sticky-pull-request-comment@v2
  with:
    path: coverage-report.md
```

## Coverage output formats

In addition to the Markdown report (`output_file`), the extension can emit
three additional formats from the same test run. All four are independent:
setting any combination writes every configured format, and a write failure
on one format does not block the others (subscriber emits a `WARNING`, the
merge CLI emits `FATAL` and exits non-zero).

| Format | Parameter / Flag | When to use | Reference |
|---|---|---|---|
| Markdown | `output_file` / `--output-file` | PR comments, GitHub Step Summary | (above) |
| JUnit XML | `junit_output` / `--junit-output` | CI test-report tabs (GitLab CI, Jenkins, SonarQube, Bitrise, CircleCI) | — |
| JSON | `json_output` / `--json-output` | Custom dashboards, scripted gating, analytics pipelines | [`coverage-json-schema.md`](coverage-json-schema.md) |
| HTML | `html_output` / `--html-output` | Self-contained artifact for human review (PR comments, CI artifact preview, offline inspection) | [`coverage-html-output.md`](coverage-html-output.md) |

Example: write every format from one PHPUnit run:

```xml
<extensions>
    <bootstrap class="Studio\Gesso\PHPUnit\OpenApiCoverageExtension">
        <parameter name="spec_base_path" value="openapi/bundled"/>
        <parameter name="specs" value="front,admin"/>
        <parameter name="output_file" value="build/coverage.md"/>
        <parameter name="junit_output" value="build/coverage.junit.xml"/>
        <parameter name="json_output" value="build/coverage.json"/>
        <parameter name="html_output" value="build/coverage.html"/>
    </bootstrap>
</extensions>
```

Same idea from the paratest merge CLI:

```bash
vendor/bin/gesso coverage:merge \
  --spec-base-path=openapi/bundled \
  --specs=front,admin \
  --output-file=build/coverage.md \
  --junit-output=build/coverage.junit.xml \
  --json-output=build/coverage.json \
  --html-output=build/coverage.html
```

> **Note:** `GITHUB_STEP_SUMMARY` is Markdown-only by design — GitHub renders
> the file as Markdown, so an HTML/JUnit/JSON payload would be escaped. Use
> the per-format flags above for artifact uploads and `output_file` /
> `github_step_summary` for the in-PR summary.

## Spec patch coverage gate

`gesso coverage:gate` fails the build when a pull request changes an operation
that no test exercises — see [Spec patch coverage gate](coverage-gate.md) for
the full behaviour and exit codes. It needs the base branch's copy of the spec,
so check out with enough history for `git show` to reach it:

```yaml
name: Spec patch coverage

on: pull_request

jobs:
  gate:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
        with:
          fetch-depth: 0

      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'

      - run: composer install --prefer-dist --no-progress

      - name: Run tests with JSON coverage
        run: vendor/bin/phpunit

      - name: Fetch the base spec
        run: |
          git fetch --no-tags --depth=1 origin "${{ github.base_ref }}"
          git show "origin/${{ github.base_ref }}:openapi.json" > "${RUNNER_TEMP}/base.json"

      - name: Gate the changed operations
        run: |
          set -o pipefail
          vendor/bin/gesso coverage:gate \
            --base-spec="${RUNNER_TEMP}/base.json" \
            --spec=openapi.json \
            --coverage=build/coverage.json \
            --format=markdown | tee -a "${GITHUB_STEP_SUMMARY}"
```

The PHPUnit run must emit `build/coverage.json`; add the `json_output`
parameter (or `coverage:merge --json-output` for parallel runs) as shown in
[Coverage output formats](#coverage-output-formats).

> **Note:** `set -o pipefail` is required. GitHub Actions runs `run:` blocks
> under `bash -e` without it, so the step would take `tee`'s exit code and the
> gate's failure would be swallowed.

## Partial test runs (`--filter`, `--testsuite`, path args, …)

If you commit your coverage doc to the repo (e.g. `docs/openapi-coverage.md`
under `output_file`), running a subset of the suite locally is no longer a
hazard: when the extension detects a partial run, **every persistent
coverage artifact write is skipped** and a single stderr `WARNING` lists
which targets were skipped.

A run is treated as partial when any of these PHPUnit selection signals
are active (mirrors PHPUnit's own filter set plus the `TestSuiteBuilder`-
stage selections that bypass the `TestSuite\Filtered` event):

- positional path arguments (`phpunit tests/Feature/Foo/`)
- `--filter` / `--exclude-filter`
- `--group` / `--exclude-group`
- `--testsuite` / `--exclude-testsuite`
- `--covers` / `--uses`
- `--requires-php-extension`

Skipped artifacts: `output_file`, `junit_output`, `json_output`,
`html_output`, and `GITHUB_STEP_SUMMARY`. Console rendering still prints
(it's transient, scoped to your terminal). The optional coverage threshold
gate (`min_endpoint_coverage` / `min_response_coverage`) is also skipped —
a subset cannot prove a suite-wide coverage rate, so evaluating it would
fail every suite-selecting run (issue #438). A one-line NOTE names the
selection signal, mirroring the `strict_required` skip:

```text
[Gesso] NOTE: the coverage threshold gate is skipped on partial runs
(--testsuite) because a subset cannot prove a suite-wide coverage rate.
Run the full suite to evaluate the gate.
```

The [coverage baseline](coverage-baseline.md) gate is skipped on the same
signals, for the same reason, and additionally on any run that did not
complete cleanly.

This means a CI matrix that shards by `--testsuite` can keep the gate
configured in the shared `phpunit.xml`: shard jobs skip it, and only a
full-suite run (or the `coverage:merge` step for parallel runs) evaluates
it. If a `--testsuite` selection *is* your canonical full run, see the
`default_testsuite_as_full` opt-in below.

```text
$ vendor/bin/phpunit --filter UserTest
# … console summary prints as usual …
[OpenAPI Coverage] WARNING: Skipping output_file, junit_output write
because PHPUnit is running a partial subset (--filter). Coverage reports
are not written on partial runs to avoid overwriting persistent docs with
subset data. Re-run the full suite to refresh.
```

Paratest workers (`TEST_TOKEN` set) are unaffected — they always write
their sidecar so the merge CLI can aggregate. The persistent-write skip
only fires on the sequential (or merge) rendering path.

### default_testsuite_as_full opt-in

`phpunit.xml` で `defaultTestSuite` を宣言しているプロジェクトでは、引数なしの
`phpunit` 実行でも PHPUnit が `Configuration::includeTestSuites()` にその名前を
詰める。結果として、ユーザーから見れば "canonical full run" のはずの実行が
`--testsuite` 由来の partial と判定され、`strict_required` ゲート等が
スキップされる (issue #236)。

これがユーザー側のワークフローに合致しているなら、`default_testsuite_as_full`
を立てて partial 判定を解除できる:

```xml
<bootstrap class="Studio\Gesso\PHPUnit\OpenApiCoverageExtension">
    <parameter name="default_testsuite_as_full" value="true"/>
    <parameter name="strict_required" value="warn"/>
</bootstrap>
```

挙動:

- `includeTestSuites` が `[defaultTestSuite]` と完全一致するときだけ
  `--testsuite` 由来の partial signal を打ち消す。`--testsuite=Other` のように
  default を越える選択をした場合や、他の partial signal (`--filter`, path 引数, …)
  が立っている場合は引き続き partial。
- 副作用として `output_file` / `junit_output` 等の persistent 書き込みも実行される。
  defaultTestSuite に含まれないテストスイート (例: 別 job で動かす Integration suite)
  が抜けた状態のレポートが書かれる点を許容できる場合のみ有効化する。
- 設定ミス検知: `default_testsuite_as_full=true` だが `defaultTestSuite` 属性が
  未設定 (`<phpunit defaultTestSuite="...">` 不在) や空文字 (`defaultTestSuite=""`)
  の場合、opt-in が黙って no-op になるのを避けるため bootstrap で stderr に
  WARNING を出す (FATAL ではない — 設計上 opt-in を立てたまま XML を直さなくても
  実行可能なため)。
