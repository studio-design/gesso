// GitHub's heading-anchor algorithm, used as VitePress's `markdown.anchor.slugify`.
//
// The repository's Markdown is read on two surfaces — GitHub renders the files
// directly, and VitePress publishes them — so one `#fragment` has to resolve on
// both. VitePress's own slugifier disagrees with GitHub for any heading carrying
// an em dash, an underscore, a leading digit, or a `--flag`, which was 51 of the
// site's 448 headings. Overriding it here makes the published anchors the same
// ones GitHub emits, which is also the spelling `lychee --include-fragments`
// checks against; `scripts/verify-docs-anchors.mjs` pins that they stay equal.
//
// Mirrors https://github.com/Flet/github-slugger: lower-case, drop punctuation
// and symbols but keep `-` and `_`, then turn spaces into hyphens. Deliberately
// no trimming — GitHub keeps the leading/trailing hyphen a stripped character
// leaves behind.
const punctuation =
  /[\0-\x1F!-,.\/:-@[-^`{-~\xA1-\xBF\xD7\xF7‐-‧‰-⁞←-⯿️]/g

export const githubSlug = (title) => title.toLowerCase().replace(punctuation, '').replace(/ /g, '-')
