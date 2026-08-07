// GitHub's heading-anchor algorithm, used as VitePress's `markdown.anchor.slugify`.
//
// The repository's Markdown is read on two surfaces — GitHub renders the files
// directly, and VitePress publishes them — so one `#fragment` has to resolve on
// both. VitePress's own slugifier disagrees with GitHub for any heading carrying
// an em dash, an underscore, a leading digit, or a `--flag`, which was 51 of the
// site's 448 headings. Overriding it here makes the published anchors the ones
// GitHub emits, which is also the spelling `lychee --include-fragments` resolves
// against.
//
// The rule is html-pipeline's `TocFilter`: drop every character that is not a
// word character, a space, or a hyphen; turn spaces into hyphens; lower-case.
// That is the algorithm lychee implements, deliberately in preference to
// Flet/github-slugger, whose regex is observational and known to disagree:
// https://github.com/lycheeverse/lychee/blob/master/lychee-lib/src/extract/fragments.rs
// https://github.com/gjtorikian/html-pipeline/blob/f13a153/lib/html/pipeline/toc_filter.rb#L30
//
// `\p{Alphabetic}\p{M}\p{Nd}\p{Pc}\p{Join_Control}` is Rust's Unicode `\w`,
// which stands in for Ruby's `\p{Word}` — so `µ` survives as a letter while an
// emoji does not. Lower-casing is per character on purpose: applying it to the
// whole string would bring in context-sensitive rules (Greek final sigma) that
// GitHub does not apply.
//
// This is a best-effort reimplementation, and lychee's own version of it has
// changed between releases, so nothing here is trusted: after every build,
// `scripts/emit-anchor-probe.mjs` feeds the anchors this produced back through
// lychee. A heading whose characters this rule gets wrong fails CI instead of
// shipping a divergent anchor.
const nonWord = /[^\p{Alphabetic}\p{M}\p{Nd}\p{Pc}\p{Join_Control} -]/gu

export const githubSlug = (title) =>
  Array.from(title.replace(nonWord, '').replaceAll(' ', '-'), (character) =>
    character.toLowerCase()
  ).join('')
