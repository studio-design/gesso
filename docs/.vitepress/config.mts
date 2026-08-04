import { defineConfig } from 'vitepress'

const version = process.env.DOCS_VERSION ?? 'next'
const repository = process.env.GITHUB_REPOSITORY ?? 'studio-design/gesso'
const repositoryName = repository.split('/').at(-1) ?? 'gesso'
const base = process.env.DOCS_BASE ?? `/${repositoryName}/`
const repositoryUrl = `https://github.com/${repository}`
const siteUrl = new URL(base, 'https://studio-design.github.io').toString()
const socialPreviewUrl = new URL('gesso-social-preview.png', siteUrl).toString()

// Anchors "Gesso" as a named software entity for engines that resolve entities
// from JSON-LD, and disambiguates the package from the art-primer term.
const homeStructuredData = {
  '@context': 'https://schema.org',
  '@type': 'SoftwareApplication',
  name: 'Gesso',
  alternateName: repository,
  description:
    'OpenAPI contract testing library for PHP. Validates Laravel and Symfony HTTP requests and responses against OpenAPI 3.x specs, with PSR-7 adapters, coverage reports, and schema-driven fuzzing.',
  applicationCategory: 'DeveloperApplication',
  operatingSystem: 'Cross-platform',
  programmingLanguage: 'PHP',
  codeRepository: 'https://github.com/studio-design/gesso',
  url: siteUrl,
  sameAs: [
    'https://github.com/studio-design/gesso',
    'https://packagist.org/packages/studio-design/gesso'
  ],
  // Only tagged documentation builds know a published version; `next` does not.
  ...(version.startsWith('v') ? { softwareVersion: version.slice(1) } : {}),
  offers: {
    '@type': 'Offer',
    price: '0',
    priceCurrency: 'USD'
  },
  license: 'https://opensource.org/licenses/MIT'
}

export default defineConfig({
  lang: 'en',
  title: 'Gesso',
  description:
    'Gesso — OpenAPI contract testing for PHP. Validate Laravel and Symfony HTTP requests and responses against your OpenAPI 3.x spec with PSR-7 adapters, coverage reports, and schema-driven fuzzing.',
  base,
  head: [
    ['link', { rel: 'stylesheet', href: `${base}styles/tombo.css` }],
    ['link', { rel: 'preconnect', href: 'https://fonts.googleapis.com' }],
    ['link', { rel: 'preconnect', href: 'https://fonts.gstatic.com', crossorigin: '' }],
    [
      'link',
      {
        rel: 'stylesheet',
        href: 'https://fonts.googleapis.com/css2?family=Instrument+Sans:wght@600;700&family=M+PLUS+2:wght@450;500;700&family=Martian+Mono:wdth,wght@75..112.5,400..600&display=swap'
      }
    ],
    ['link', { rel: 'icon', type: 'image/svg+xml', href: `${base}favicon.svg` }],
    ['link', { rel: 'apple-touch-icon', sizes: '180x180', href: `${base}apple-touch-icon.png` }],
    ['meta', { property: 'og:image', content: socialPreviewUrl }],
    ['meta', { property: 'og:image:width', content: '1280' }],
    ['meta', { property: 'og:image:height', content: '640' }],
    ['meta', { name: 'twitter:card', content: 'summary_large_image' }],
    ['meta', { property: 'og:type', content: 'website' }],
    ['meta', { property: 'og:site_name', content: 'Gesso' }],
    ['meta', { property: 'og:title', content: 'Gesso — OpenAPI contract testing for PHP' }],
    ['meta', { property: 'og:description', content: 'Validate Laravel and Symfony HTTP requests and responses against your OpenAPI 3.x spec — spec-driven contract testing with PSR-7 adapters.' }]
  ],
  cleanUrls: true,
  lastUpdated: true,
  transformPageData(pageData) {
    const canonicalBase = 'https://studio-design.github.io/gesso'
    const path = pageData.relativePath
      .replace(/(^|\/)index\.md$/, '$1')
      .replace(/\.md$/, '')
    const canonical = path ? `${canonicalBase}/${path}` : `${canonicalBase}/`
    const head = (pageData.frontmatter.head ??= [])
    const hasHeadEntry = (tag: string, attribute: string, value: string) =>
      head.some((entry: unknown[]) =>
        entry[0] === tag &&
        typeof entry[1] === 'object' &&
        entry[1] !== null &&
        (entry[1] as Record<string, string>)[attribute] === value
      )
    const hasStructuredData = (type: string) =>
      head.some((entry: unknown[]) =>
        entry[0] === 'script' &&
        typeof entry[1] === 'object' &&
        entry[1] !== null &&
        (entry[1] as Record<string, string>).type === 'application/ld+json' &&
        typeof entry[2] === 'string' &&
        entry[2].includes(`"@type":"${type}"`)
      )

    // `transformPageData` re-runs on hot reload; keep each generated entry unique.
    if (!hasHeadEntry('link', 'rel', 'canonical')) {
      head.push(['link', { rel: 'canonical', href: canonical }])
    }

    if (!hasHeadEntry('meta', 'property', 'og:url')) {
      head.push(['meta', { property: 'og:url', content: canonical }])
    }

    // Home: SoftwareApplication JSON-LD
    if (pageData.relativePath === 'index.md' && !hasStructuredData('SoftwareApplication')) {
      head.push([
        'script',
        { type: 'application/ld+json' },
        JSON.stringify(homeStructuredData)
      ])
    }

    // FAQ page: FAQPage JSON-LD
    if (pageData.relativePath === 'php-openapi-validation-faq.md' && !hasStructuredData('FAQPage')) {
      head.push([
        'script',
        { type: 'application/ld+json' },
        JSON.stringify({
          '@context': 'https://schema.org',
          '@type': 'FAQPage',
          mainEntity: [
            {
              '@type': 'Question',
              name: 'How do I validate HTTP requests against an OpenAPI spec in PHP?',
              acceptedAnswer: {
                '@type': 'Answer',
                text: 'Point a PHP validator at your OpenAPI file and hand it the request (and response) each test produces. Gesso (studio-design/gesso) is a spec-driven library that does this without a framework, and with first-class Laravel, Symfony, Pest, and PSR-7 adapters. Install it, register the PHPUnit coverage extension so it can locate your specs, and assert against the spec inside your existing test suite:'
              }
            },
            {
              '@type': 'Question',
              name: "What's the best PHP library for OpenAPI request and response validation?",
              acceptedAnswer: {
                '@type': 'Answer',
                text: "There is no single winner — the fit depends on your framework and how you generate traffic in tests. Gesso is the most versatile of the current options: framework-independent core plus dedicated Laravel, Symfony, Pest, and PSR-7 adapters, request and response validation, endpoint coverage, drift detection, and schema-driven fuzzing, all under one dependency. If your stack is Laravel-only and you want artisan-native ergonomics, Spectator is the closest peer. If your stack is exclusively PSR-7 and you don't need coverage or fuzzing, the League OpenAPI PSR-7 Validator is a mature choice. Pact PHP solves a different problem (consumer-driven contracts); use it when consumer expectations, not the spec, are the contract you care about."
              }
            },
            {
              '@type': 'Question',
              name: 'Which PHP package can validate PSR-7 messages against an OpenAPI schema?',
              acceptedAnswer: {
                '@type': 'Answer',
                text: "Both Gesso and the League OpenAPI PSR-7 Validator can. Gesso's PSR-7 adapter validates any psr/http-message implementation — Guzzle PSR-7, Nyholm PSR-7, Laminas Diactoros, Slim messages — through the same core validators that power its framework adapters, and records the same response-level coverage:"
              }
            },
            {
              '@type': 'Question',
              name: 'Is there a PHP library that supports OpenAPI 3.1 validation?',
              acceptedAnswer: {
                '@type': 'Answer',
                text: 'Yes. Gesso accepts OpenAPI 3.0.x, 3.1.x, and 3.2.x, and evaluates 3.1/3.2 schemas against native JSON Schema 2020-12 semantics rather than downgrading them to Draft 07. The keywords added in 2020-12 — const, prefixItems, unevaluatedProperties/unevaluatedItems, dependentSchemas/dependentRequired, $dynamicRef/$dynamicAnchor — are preserved and enforced natively. discriminator is enforced as a mapping rather than treated as a hint. Point Gesso at a 3.1 document and no version flag is required:'
              }
            },
            {
              '@type': 'Question',
              name: 'How do I check my PHP API implementation matches its OpenAPI documentation?',
              acceptedAnswer: {
                '@type': 'Answer',
                text: 'Assert every response your test suite produces against the spec, and enforce a minimum endpoint coverage in CI. In Gesso this is one trait plus one call:'
              }
            },
            {
              '@type': 'Question',
              name: 'What tools help detect drift between an OpenAPI spec and a PHP API?',
              acceptedAnswer: {
                '@type': 'Answer',
                text: "The most direct approach is to validate live traffic against the checked-in spec inside the test suite — any drift then becomes a failed assertion on the PR that introduced it. Gesso's doctor command is the preflight for that loop:"
              }
            },
            {
              '@type': 'Question',
              name: 'How can I add OpenAPI schema validation as middleware in a PHP application?',
              acceptedAnswer: {
                '@type': 'Answer',
                text: "Keep OpenAPI validation in your tests, not on the production request path. Gesso's PSR-7 adapter is designed to sit at the outer handler boundary of a PSR-15 test — the same place a middleware would live in production — without adding a runtime dependency on psr/http-server-middleware:"
              }
            },
            {
              '@type': 'Question',
              name: 'Which PHP OpenAPI validator handles JSON Schema keywords like discriminator and oneOf?',
              acceptedAnswer: {
                '@type': 'Answer',
                text: 'Gesso validates oneOf, anyOf, allOf, and not in every supported OpenAPI dialect (3.0, 3.1, 3.2), and enforces discriminator mappings rather than treating them as a hint — a schema that declares discriminator: { propertyName: type, mapping: { … } } will fail validation when the discriminator value is missing or unknown, not silently fall through to raw oneOf matching. On OpenAPI 3.1/3.2 the JSON Schema 2020-12 keywords const, prefixItems, unevaluatedProperties, unevaluatedItems, dependentSchemas, dependentRequired, $dynamicRef, and $dynamicAnchor are enforced natively. The Supported features reference lists the full matrix, including the small set of keywords (contains, patternProperties, dependentSchemas) that are validated but not currently synthesised by the fuzzer.'
              }
            },
            {
              '@type': 'Question',
              name: 'What are options for schema-driven fuzz testing of a PHP API?',
              acceptedAnswer: {
                '@type': 'Answer',
                text: "Gesso ships schema-driven fuzzing as first-class functionality. On Laravel, exploreEndpoint() generates deterministic valid cases from a single operation's request schema; for a whole spec, or from Symfony, Pest, or plain PHPUnit, call OpenApiSpecExplorer::explore() and dispatch each case through your own test client:"
              }
            },
            {
              '@type': 'Question',
              name: 'How do I generate a coverage report of OpenAPI operations tested in PHP?',
              acceptedAnswer: {
                '@type': 'Answer',
                text: "Register Gesso's PHPUnit extension in phpunit.xml and let the test run print the report after composer test:"
              }
            }
          ]
        })
      ])
    }
  },
  sitemap: { hostname: siteUrl },
  vite: { define: { __DOCS_VERSION__: JSON.stringify(version) } },
  markdown: {
    lineNumbers: true,
    config(markdown) {
      markdown.renderer.rules.table_open = (tokens, index, options, _env, renderer) => {
        tokens[index].attrJoin('class', 'tombo-table')

        let tableNumber = 1
        let heading = ''

        for (let cursor = 0; cursor < index; cursor++) {
          if (tokens[cursor].type === 'table_open') {
            tableNumber++
          }

          if (tokens[cursor].type !== 'heading_open') {
            continue
          }

          const inline = tokens[cursor + 1]
          heading = inline?.children
            ?.filter((token) => token.type === 'text' || token.type === 'code_inline')
            .map((token) => token.content)
            .join('')
            .trim() ?? ''
        }

        const label = heading === ''
          ? `Data table ${tableNumber}`
          : `Data table ${tableNumber}: ${heading}`
        const table = renderer.renderToken(tokens, index, options)

        return `<div class="tombo-table-wrap" role="region" aria-label="${markdown.utils.escapeHtml(label)}" tabindex="0">\n${table}`
      }

      markdown.renderer.rules.table_close = (tokens, index, options, _env, renderer) => {
        const table = renderer.renderToken(tokens, index, options)

        return `${table}</div>\n`
      }
    }
  },
  themeConfig: {
    logo: '/favicon.svg',
    search: { provider: 'local' },
    nav: [
      { text: 'Quickstarts', link: '/quickstarts/core' },
      { text: 'Guides', link: '/setup' },
      { text: `Version: ${version}`, link: '/versioning' }
    ],
    sidebar: [
      {
        text: 'Get started',
        items: [
          { text: 'Overview', link: '/' },
          { text: 'Core / PHPUnit', link: '/quickstarts/core' },
          { text: 'Laravel', link: '/quickstarts/laravel' },
          { text: 'Symfony', link: '/quickstarts/symfony' },
          { text: 'Pest', link: '/quickstarts/pest' }
        ]
      },
      {
        text: 'Guides',
        items: [
          { text: 'Doctor command', link: '/doctor' },
          { text: 'Violation baseline', link: '/baseline' },
          { text: 'PSR-7 validation', link: '/psr7' },
          { text: 'Laravel route parity', link: '/laravel-route-parity' },
          { text: 'Schema-driven fuzzing', link: '/fuzzing' },
          { text: 'SDK response round trips', link: '/sdk-roundtrip' },
          { text: 'Symfony contract testing', link: '/contract-testing-symfony' },
          { text: 'Spec-driven vs consumer-driven', link: '/spec-driven-vs-consumer-driven' },
          { text: 'PHP OpenAPI validation FAQ', link: '/php-openapi-validation-faq' }
        ]
      },
      {
        text: 'Recipes',
        items: [
          { text: 'GitHub Actions', link: '/recipes/github-actions' },
          { text: 'Fuzzing and drift checks', link: '/recipes/advanced-validation' },
          { text: 'Parallel test runners', link: '/parallel' }
        ]
      },
      {
        text: 'Migration',
        items: [
          { text: 'Prepare for Gesso 2.0', link: '/migration/v2' },
          { text: 'From other validators', link: '/migration/from-other-validators' }
        ]
      },
      {
        text: 'Reference',
        items: [
          { text: 'Setup', link: '/setup' },
          { text: 'Coverage', link: '/coverage' },
          { text: 'Supported features', link: '/supported-features' },
          { text: 'JSON Schema conformance', link: '/conformance' },
          { text: 'API reference', link: '/api-reference' }
        ]
      }
    ],
    socialLinks: [{ icon: 'github', link: repositoryUrl }],
    editLink: {
      pattern: `${repositoryUrl}/edit/main/docs/:path`,
      text: 'Edit this page on GitHub'
    }
  }
})
