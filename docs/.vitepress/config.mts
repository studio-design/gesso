import { defineConfig } from 'vitepress'

const version = process.env.DOCS_VERSION ?? 'next'
const repository = process.env.GITHUB_REPOSITORY ?? 'studio-design/gesso'
const repositoryName = repository.split('/').at(-1) ?? 'gesso'
const base = process.env.DOCS_BASE ?? `/${repositoryName}/`
const repositoryUrl = `https://github.com/${repository}`
const siteUrl = new URL(base, 'https://studio-design.github.io').toString()
const socialPreviewUrl = new URL('gesso-social-preview.png', siteUrl).toString()

export default defineConfig({
  title: 'Gesso',
  description: 'OpenAPI contract testing for PHP',
  base,
  head: [
    ['link', { rel: 'icon', type: 'image/svg+xml', href: `${base}favicon.svg` }],
    ['link', { rel: 'apple-touch-icon', sizes: '180x180', href: `${base}apple-touch-icon.png` }],
    ['meta', { property: 'og:image', content: socialPreviewUrl }],
    ['meta', { property: 'og:image:width', content: '1280' }],
    ['meta', { property: 'og:image:height', content: '640' }],
    ['meta', { name: 'twitter:card', content: 'summary_large_image' }]
  ],
  cleanUrls: true,
  lastUpdated: true,
  sitemap: { hostname: siteUrl },
  vite: { define: { __DOCS_VERSION__: JSON.stringify(version) } },
  markdown: { lineNumbers: true },
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
          { text: 'Schema-driven fuzzing', link: '/fuzzing' }
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
        items: [{ text: 'From other validators', link: '/migration/from-other-validators' }]
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
    },
    footer: { message: 'Released under the MIT License.' }
  }
})
