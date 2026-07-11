import { readFile } from 'node:fs/promises'

const repository = process.env.GITHUB_REPOSITORY ?? 'studio-design/gesso'
const repositoryName = repository.split('/').at(-1) ?? 'gesso'
const expectedBase = process.env.DOCS_BASE ?? `/${repositoryName}/`
const expectedSiteUrl = new URL(expectedBase, 'https://studio-design.github.io').toString()
const html = await readFile('docs/.vitepress/dist/index.html', 'utf8')

if (!html.includes(`${expectedBase}assets/`)) {
  throw new Error(`Generated documentation does not use base path ${expectedBase}`)
}

const expectedBrandAssets = [
  `href="${expectedBase}favicon.svg"`,
  `href="${expectedBase}apple-touch-icon.png"`,
  `content="${expectedSiteUrl}gesso-social-preview.png"`,
  `src="${expectedBase}gesso-logo.png"`
]

for (const asset of expectedBrandAssets) {
  if (!html.includes(asset)) {
    throw new Error(`Generated documentation does not reference branded asset: ${asset}`)
  }
}
