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
