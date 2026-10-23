# GitHub Actions coverage recipe

Register the PHPUnit extension with `output_file`; when the job exposes
`GITHUB_STEP_SUMMARY`, the extension appends the same Markdown report to it
automatically:

```xml
<parameter name="output_file" value="build/openapi-coverage.md"/>
```

Then preserve the same report as a workflow artifact:

```yaml
- name: Run contract tests
  run: vendor/bin/phpunit

- name: Upload OpenAPI coverage
  if: always()
  uses: actions/upload-artifact@v4
  with:
    name: openapi-coverage
    path: build/openapi-coverage.md
```

GitHub renders the summary on the workflow run page while the artifact remains downloadable. Add a [coverage threshold](../coverage.md#coverage-threshold-gate) when missing operations should fail CI.
