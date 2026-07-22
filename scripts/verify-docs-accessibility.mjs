import { readFile, readdir } from 'node:fs/promises'
import { join } from 'node:path'

const docsIndex = await readFile('docs/index.md', 'utf8')

if (!/^layout: home$/mu.test(docsIndex) || !/^markdownStyles: false$/mu.test(docsIndex)) {
  throw new Error('The documentation homepage must use the home layout without Markdown styles')
}

const distDirectory = 'docs/.vitepress/dist'
const home = await readFile(join(distDirectory, 'index.html'), 'utf8')

if (!home.includes('<footer class="tombo-site-footer tombo-shell"')) {
  throw new Error('The documentation homepage does not expose its site footer as a footer landmark')
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
