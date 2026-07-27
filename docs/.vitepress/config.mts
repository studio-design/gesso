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
    'OpenAPI 3.0/3.1/3.2 contract testing for PHP. Framework-independent core with Laravel, Symfony, Pest, and PSR-7 adapters. PHPUnit coverage, request/response validation, fuzzing, and drift detection.',
  applicationCategory: 'DeveloperApplication',
  operatingSystem: 'PHP 8.3+',
  url: siteUrl,
  codeRepository: repositoryUrl,
  programmingLanguage: 'PHP',
  license: 'https://opensource.org/licenses/MIT',
  // Only tagged documentation builds know a published version; `next` does not.
  ...(version.startsWith('v') ? { softwareVersion: version.slice(1) } : {}),
  offers: {
    '@type': 'Offer',
    price: '0',
    priceCurrency: 'USD'
  }
}

export default defineConfig({
  title: 'Gesso',
  description: 'OpenAPI contract testing for PHP',
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
    ['meta', { name: 'twitter:card', content: 'summary_large_image' }]
  ],
  cleanUrls: true,
  lastUpdated: true,
  transformPageData(pageData) {
    if (pageData.relativePath !== 'index.md') {
      return
    }

    const head = (pageData.frontmatter.head ??= [])
    const isStructuredData = (entry: unknown[]) =>
      entry[0] === 'script' &&
      typeof entry[1] === 'object' &&
      entry[1] !== null &&
      (entry[1] as Record<string, string>).type === 'application/ld+json'

    // `transformPageData` re-runs on hot reload; keep exactly one block.
    if (head.some(isStructuredData)) {
      return
    }

    head.push([
      'script',
      { type: 'application/ld+json' },
      JSON.stringify(homeStructuredData)
    ])
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
          { text: 'PSR-7 validation', link: '/psr7' },
          { text: 'Laravel route parity', link: '/laravel-route-parity' },
          { text: 'Schema-driven fuzzing', link: '/fuzzing' },
          { text: 'Symfony contract testing', link: '/contract-testing-symfony' }
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
