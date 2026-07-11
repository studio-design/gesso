# Coverage HTML Output

`studio-design/openapi-contract-testing` can emit a single self-contained HTML
report via the `html_output` PHPUnit Extension parameter or the `--html-output`
flag on `openapi-coverage-merge`. The file is intended for human review (PR
comments, CI artifact preview, offline inspection).

A sample document is committed in the repository at [`docs/samples/coverage.html`](https://github.com/studio-design/gesso/blob/main/docs/samples/coverage.html).

## Design choices

- **Single file.** Inline CSS, no JavaScript, no external assets. Drops cleanly
  as a CI artifact and renders offline in any modern browser.
- **`<details>`/`<summary>` for per-endpoint detail.** Reviewers can expand only
  the endpoints they care about without depending on JavaScript.
- **In-page anchor links.** The top-level endpoint list links to per-endpoint
  detail sections so reviewers can jump directly to interesting rows.
- **Safe escaping.** All spec-derived strings (paths, operation IDs, skip
  reasons, content types) pass through
  `htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')` so hostile
  spec content cannot inject markup.
- **State markers.** Each endpoint or response row carries a `state-<value>`
  CSS class so styling can be customised without changing the renderer.
  Endpoint-level classes mirror `EndpointCoverageState`: `state-all-covered`,
  `state-partial`, `state-uncovered`, `state-request-only`. Response-row
  classes mirror `ResponseCoverageState`: `state-validated`, `state-skipped`,
  `state-uncovered`. Only `state-uncovered` is shared between the two enums.

## Generating the file

PHPUnit Extension:

```xml
<extensions>
  <bootstrap class="Studio\OpenApiContractTesting\PHPUnit\OpenApiCoverageExtension">
    <parameter name="spec_base_path" value="openapi" />
    <parameter name="html_output" value="build/coverage.html" />
  </bootstrap>
</extensions>
```

Paratest merge CLI:

```bash
vendor/bin/openapi-coverage-merge \
  --spec-base-path=openapi \
  --html-output=build/coverage.html
```

## Multiple formats

`output_file`, `junit_output`, `json_output`, and `html_output` are independent;
setting any combination writes all configured formats. A write failure on one
format does not block the others. Severity follows the existing convention:
subscriber WARN-and-continue, CLI FATAL + exit 1.

**Note on `GITHUB_STEP_SUMMARY`.** The HTML output is *not* appended to
`GITHUB_STEP_SUMMARY` (which is Markdown-only by design; GitHub renders the
file as Markdown, so an HTML payload would be escaped). Use `html_output` for
artifact uploads and `output_file` / `github_step_summary` for the Markdown
summary.

## Future extensions

The current shape is intentionally minimal. If a multi-page report (per-spec
files, asset directory) is requested, a separate `html_output_dir` parameter
will be added — `html_output` will continue to mean "single self-contained
file" so existing pipelines do not need to migrate.
