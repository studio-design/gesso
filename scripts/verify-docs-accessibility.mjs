import { readFile, readdir } from 'node:fs/promises'
import { join } from 'node:path'

const blockAfter = (source, marker) => {
  const markerIndex = source.indexOf(marker)

  if (markerIndex === -1) {
    throw new Error(`Required CSS block is missing: ${marker}`)
  }

  const openBraceIndex = source.indexOf('{', markerIndex + marker.length)

  if (openBraceIndex === -1) {
    throw new Error(`Required CSS block has no opening brace: ${marker}`)
  }

  let depth = 1

  for (let index = openBraceIndex + 1; index < source.length; index++) {
    if (source[index] === '{') {
      depth++
    } else if (source[index] === '}') {
      depth--
    }

    if (depth === 0) {
      return source.slice(openBraceIndex + 1, index)
    }
  }

  throw new Error(`Required CSS block is not closed: ${marker}`)
}

const declarationsFor = (source, selector) => new Map(
  blockAfter(source, selector)
    .split(';')
    .map((declaration) => declaration.trim())
    .filter(Boolean)
    .map((declaration) => {
      const separatorIndex = declaration.indexOf(':')

      return [
        declaration.slice(0, separatorIndex).trim(),
        declaration.slice(separatorIndex + 1).trim()
      ]
    })
)

const assertDeclaration = (declarations, property, expected, context) => {
  if (declarations.get(property) !== expected) {
    throw new Error(`${context} must set ${property}: ${expected}`)
  }
}

const docsIndex = await readFile('docs/index.md', 'utf8')
const documentationStyles = await readFile('docs/.vitepress/theme/style.css', 'utf8')

if (!/^layout: home$/mu.test(docsIndex) || !/^markdownStyles: false$/mu.test(docsIndex)) {
  throw new Error('The documentation homepage must use the home layout without Markdown styles')
}

const printStyles = blockAfter(documentationStyles, '@media print')

assertDeclaration(
  declarationsFor(printStyles, 'html.dark'),
  'color-scheme',
  'light',
  'Dark-mode print styles'
)
assertDeclaration(
  declarationsFor(printStyles, 'html.dark .vp-doc div[class*="language-"]'),
  'background',
  '#fff',
  'VitePress code block print styles'
)
assertDeclaration(
  declarationsFor(printStyles, 'html.dark .vp-doc div[class*="language-"] pre'),
  'background',
  'transparent',
  'VitePress preformatted print styles'
)
assertDeclaration(
  declarationsFor(printStyles, 'html.dark .vp-doc div[class*="language-"] pre'),
  'color',
  'var(--tombo-ink)',
  'VitePress preformatted print styles'
)
assertDeclaration(
  declarationsFor(printStyles, 'html.dark .vp-doc div[class*="language-"] code'),
  'background',
  'transparent',
  'VitePress code print styles'
)
assertDeclaration(
  declarationsFor(printStyles, 'html.dark .vp-doc div[class*="language-"] code'),
  'color',
  'var(--tombo-ink)',
  'VitePress code print styles'
)
assertDeclaration(
  declarationsFor(printStyles, 'html.dark .vp-code span'),
  'color',
  'var(--shiki-light, var(--tombo-ink))',
  'Shiki token print styles'
)

const distDirectory = 'docs/.vitepress/dist'
const home = await readFile(join(distDirectory, 'index.html'), 'utf8')
const documentationPage = await readFile(join(distDirectory, 'coverage.html'), 'utf8')
const footerFrameClasses = (html) => {
  const classAttribute = html.match(/<div class="([^"]*tombo-site-footer-frame[^"]*)">/u)?.[1]

  return new Set(classAttribute?.split(/\s+/u) ?? [])
}

if (!home.includes('<footer class="tombo-site-footer tombo-shell"')) {
  throw new Error('The documentation homepage does not expose its site footer as a footer landmark')
}

if (!footerFrameClasses(home).has('tombo-site-footer-frame') || footerFrameClasses(home).has('has-sidebar')) {
  throw new Error('The documentation homepage footer must remain aligned to the page shell')
}

if (!footerFrameClasses(documentationPage).has('has-sidebar')) {
  throw new Error('Documentation page footers must account for the desktop sidebar')
}

const hasDualThemeShikiTokens = documentationPage.includes('class="shiki shiki-themes') &&
  documentationPage.includes('--shiki-light:') &&
  documentationPage.includes('--shiki-dark:')

if (!hasDualThemeShikiTokens) {
  throw new Error('Generated documentation must expose dual-theme Shiki code tokens')
}

let tableCount = 0

for (const entry of await readdir(distDirectory, { recursive: true })) {
  if (!entry.endsWith('.html')) {
    continue
  }

  const html = await readFile(join(distDirectory, entry), 'utf8')
  const labels = [...html.matchAll(/class="tombo-table-wrap"[^>]*aria-label="([^"]+)"/gu)]
    .map((match) => match[1])

  if (new Set(labels).size !== labels.length) {
    throw new Error(`Generated documentation contains duplicate table region names in ${entry}`)
  }

  tableCount += labels.length
}

if (tableCount === 0) {
  throw new Error('Generated documentation does not contain any named table regions')
}
