# CI Integration

- [GitHub Actions Step Summary](#github-actions-step-summary)
- [Markdown output file](#markdown-output-file)
- [Coverage output formats](#coverage-output-formats)

## GitHub Actions Step Summary

When running in GitHub Actions, the extension **automatically** detects the `GITHUB_STEP_SUMMARY` environment variable and appends a Markdown coverage report to the job summary. No configuration needed.

> **Note:** Both features are independent — when running in GitHub Actions with `output_file` configured, the Markdown report is written to both the file and the Step Summary.

## Markdown output file

Use the `output_file` parameter to write a Markdown report to a file. This is useful for posting coverage as a PR comment:

```xml
<extensions>
    <bootstrap class="Studio\OpenApiContractTesting\PHPUnit\OpenApiCoverageExtension">
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
    <bootstrap class="Studio\OpenApiContractTesting\PHPUnit\OpenApiCoverageExtension">
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
vendor/bin/openapi-coverage-merge \
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
(it's transient, scoped to your terminal), and the optional coverage
threshold gate (`min_endpoint_coverage` / `min_response_coverage`) still
evaluates against the in-memory subset — re-run the full suite to refresh
the persistent doc.

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

### `defaultTestSuite` と partial 判定の opt-in 解除

`phpunit.xml` で `defaultTestSuite` を宣言しているプロジェクトでは、引数なしの
`phpunit` 実行でも PHPUnit が `Configuration::includeTestSuites()` にその名前を
詰める。結果として、ユーザーから見れば "canonical full run" のはずの実行が
`--testsuite` 由来の partial と判定され、`strict_required` ゲート等が
スキップされる (issue #236)。

これがユーザー側のワークフローに合致しているなら、`default_testsuite_as_full`
を立てて partial 判定を解除できる:

```xml
<bootstrap class="Studio\OpenApiContractTesting\PHPUnit\OpenApiCoverageExtension">
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
