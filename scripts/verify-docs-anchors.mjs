// Pins that the published heading anchors are the ones GitHub emits.
//
// `lychee --include-fragments` validates deep links against GitHub's spelling of
// an anchor. That only proves anything about the published site while VitePress
// slugifies the same way, which it does not by default — see
// docs/.vitepress/github-slug.mjs. If that override ever stops taking effect the
// Markdown links stay green and every deep link into the site silently 404s, so
// check the built HTML directly.
import { readFile } from 'node:fs/promises'
import { glob } from 'node:fs/promises'

import { githubSlug } from '../docs/.vitepress/github-slug.mjs'

const entities = { amp: '&', lt: '<', gt: '>', quot: '"', '#39': "'" }

// The rendered heading text, which is what GitHub slugifies. Everything from the
// permalink `<a>` onwards is VitePress chrome, not part of the heading.
const headingText = (html) =>
  html
    .replace(/<[^>]*>/g, '')
    .replace(/&(amp|lt|gt|quot|#39);/g, (match, name) => entities[name] ?? match)
    .trim()

const failures = []
let checked = 0

for await (const file of glob('docs/.vitepress/dist/**/*.html')) {
  const html = await readFile(file, 'utf8')

  for (const [, id, rendered] of html.matchAll(
    /<h[1-6] id="([^"]*)"[^>]*>([\s\S]*?)<a class="header-anchor"/g
  )) {
    checked++
    const expected = githubSlug(headingText(rendered))

    // markdown-it-anchor disambiguates a repeated heading with a numeric suffix,
    // the same way github-slugger does.
    if (id !== expected && !new RegExp(`^${expected}-\\d+$`).test(id)) {
      failures.push(`${file}\n  heading  ${headingText(rendered)}\n  id       ${id}\n  expected ${expected}`)
    }
  }
}

if (checked === 0) {
  throw new Error('No documentation headings found — run `npm run docs:build` first')
}

if (failures.length > 0) {
  throw new Error(
    `${failures.length} of ${checked} documentation headings do not use GitHub's anchor spelling:\n\n${failures.join('\n\n')}`
  )
}

console.log(`Verified ${checked} documentation heading anchors against GitHub's spelling.`)
