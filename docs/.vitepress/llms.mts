// Agents fetch the Markdown behind an llms.txt entry, not the rendered HTML
// (Claude Code pulled 76% of pages as Markdown in Evil Martians' logs). After
// the HTML build this mirrors docs/ into dist/ so every page is also served
// verbatim at <page>.md, concatenates the curated llms.txt pages into
// llms-full.txt, and fails the build when the hand-written llms.txt drifts
// from the sidebar. See https://github.com/studio-design/gesso/issues/398.
import { cp, readdir, readFile, writeFile } from 'node:fs/promises'
import { join } from 'node:path'
import type { DefaultTheme, SiteConfig } from 'vitepress'

// The published host; the GitHub Pages deploy serves `.md` as text/markdown.
export const canonicalBase = 'https://studio-design.github.io/gesso'

// Sidebar `/setup` → …/gesso/setup.md; `/adr/` → …/gesso/adr/index.md. The
// home page is a Vue component shell, so its entry stays on the HTML page.
const markdownUrl = (link: string) =>
  link === '/' ? `${canonicalBase}/` : `${canonicalBase}${link.replace(/\/$/, '/index')}.md`

const sidebarLinks = (items: DefaultTheme.SidebarItem[]): string[] =>
  items.flatMap((item) => [...(item.link ? [item.link] : []), ...sidebarLinks(item.items ?? [])])

export async function emitMarkdown(config: SiteConfig<DefaultTheme.Config>): Promise<void> {
  const { srcDir, outDir } = config

  // Vite already publishes public/; .vitepress/ holds config, theme, and dist itself.
  for (const entry of await readdir(srcDir)) {
    if (entry !== '.vitepress' && entry !== 'public') {
      await cp(join(srcDir, entry), join(outDir, entry), { recursive: true })
    }
  }

  const { sidebar = [], nav = [] } = config.site.themeConfig
  const expected = new Set(
    [...sidebarLinks(sidebar as DefaultTheme.SidebarItem[]), ...nav.flatMap((item) => ('link' in item ? [item.link] : []))]
      .map(markdownUrl)
  )
  const index = await readFile(join(srcDir, 'public/llms.txt'), 'utf8')
  const listed = [...index.matchAll(/\]\((https:\/\/studio-design\.github\.io\/gesso\/[^)]*)\)/g)].map(([, url]) => url)
  const missing = [...expected].filter((url) => !listed.includes(url))
  const unexpected = listed.filter((url) => !expected.has(url))

  if (missing.length > 0 || unexpected.length > 0) {
    throw new Error(
      'docs/public/llms.txt does not match the sidebar and nav in docs/.vitepress/config.mts.\n' +
        missing.map((url) => `  missing:    ${url}`).concat(unexpected.map((url) => `  unexpected: ${url}`)).join('\n')
    )
  }

  const sections = await Promise.all(
    listed
      .filter((url) => url.endsWith('.md'))
      .map(async (url) => {
        const source = await readFile(join(srcDir, url.slice(canonicalBase.length + 1)), 'utf8')

        return `Source: ${url}\n\n${source.replace(/^---\n[\s\S]*?\n---\n+/, '')}`
      })
  )

  await writeFile(
    join(outDir, 'llms-full.txt'),
    `# Gesso\n\n> Every page listed in llms.txt, concatenated in the same order. Each page begins with its Source URL.\n\n${sections.join('\n\n---\n\n')}`
  )
}
