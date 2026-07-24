<script setup lang="ts">
import { withBase } from 'vitepress'

declare const __DOCS_VERSION__: string

const docsVersion = __DOCS_VERSION__

const features = [
  {
    id: 'F-01',
    title: 'Contract enforcement',
    detail: 'Validate requests and responses with OpenAPI 3.0, 3.1, and 3.2 semantics.',
    meta: 'SPEC / OPENAPI 3.0 · 3.1 · 3.2',
    href: '/supported-features'
  },
  {
    id: 'F-02',
    title: 'Visible coverage',
    detail: 'See untested operations, statuses, and content types in PHPUnit and CI.',
    meta: 'UNIT / METHOD · PATH · STATUS · CONTENT-TYPE',
    href: '/coverage'
  },
  {
    id: 'F-03',
    title: 'Framework choice',
    detail: 'Use dedicated adapters or the framework-independent validation core.',
    meta: 'ADAPTER / LARAVEL · SYMFONY · PEST · PSR-7',
    href: '/setup'
  }
]

const quickstarts = [
  { id: 'Q-01', name: 'Core / PHPUnit', href: '/quickstarts/core' },
  { id: 'Q-02', name: 'Laravel', href: '/quickstarts/laravel' },
  { id: 'Q-03', name: 'Symfony', href: '/quickstarts/symfony' },
  { id: 'Q-04', name: 'Pest', href: '/quickstarts/pest' }
]
</script>

<template>
  <main class="tombo-home">
    <section class="tombo-hero tombo-vol-lp" aria-labelledby="tombo-hero-title">
      <i class="frame-mark frame-mark-tl" aria-hidden="true"></i>
      <i class="frame-mark frame-mark-tr" aria-hidden="true"></i>
      <i class="frame-mark frame-mark-bl" aria-hidden="true"></i>
      <i class="frame-mark frame-mark-br" aria-hidden="true"></i>

      <div class="home-shell tombo-shell tombo-shell--wide hero-grid">
        <div class="hero-copy">
          <p class="tombo-kicker">GESSO — FIG. 00 / CONTRACTS, MEASURED</p>
          <h1 id="tombo-hero-title" class="tombo-display">
            OpenAPI contract<br>testing for PHP.
            <small>Validate. Measure. Ship.</small>
          </h1>

          <div class="headline-measure" aria-hidden="true">
            <div class="tombo-dim"></div>
            <p class="tombo-dimlab headline-dim-label">
              <span>headline / 02 lines</span>
              <span class="headline-dim-spec">spec / 3.0 · 3.1 · 3.2</span>
            </p>
          </div>

          <p class="hero-lead">
            PHPUnit coverage, request and response validation, fuzzing, and drift detection
            for Laravel, Symfony, Pest, PSR-7, or plain PHP.
          </p>

          <div class="hero-actions">
            <a class="tombo-btn tombo-btn--primary" :href="withBase('/quickstarts/core')">
              Choose a quickstart
            </a>
            <a class="tombo-btn tombo-btn--secondary" href="https://github.com/studio-design/gesso">
              View on GitHub
            </a>
            <span class="hero-license">MIT / PHP ≥ 8.3</span>
          </div>
        </div>

        <figure class="coverage-instrument tombo-corners">
          <b class="tombo-corner-tr" aria-hidden="true"></b>
          <b class="tombo-corner-bl" aria-hidden="true"></b>
          <figcaption class="tombo-fig">FIG. 01 — ILLUSTRATIVE ENDPOINT COVERAGE</figcaption>
          <img class="instrument-logo" :src="withBase('/gesso-logo.png')" alt="">

          <p class="instrument-label">OPERATIONS COVERED</p>
          <p class="instrument-value">96.2<small>%</small></p>
          <div
            class="tombo-ruler"
            style="--tombo-ruler-value: 96.2%"
            role="img"
            aria-label="Illustrative coverage value: 96.2 percent"
          ></div>

          <ul class="coverage-rows">
            <li><code>GET /users/{id} · 200</code><span>covered</span></li>
            <li><code>POST /orders · 201</code><span>covered</span></li>
            <li><code>PATCH /users/{id} · 422</code><span>covered</span></li>
            <li><code>DELETE /orders/{id} · 204</code><span class="is-missing">untested</span></li>
          </ul>

          <p class="instrument-foot">SIDECAR / MERGED 04 SHARDS / EXAMPLE DATA</p>
        </figure>
      </div>
    </section>

    <div class="home-shell tombo-shell tombo-shell--wide home-sections tombo-sections">
      <section class="home-section" aria-labelledby="why-gesso-title">
        <header class="section-heading">
          <div>
            <div class="tombo-dim"></div>
            <p class="tombo-dimlab">section / 01 · capability</p>
          </div>
          <h2 id="why-gesso-title" class="tombo-sec">Why Gesso</h2>
        </header>

        <div class="feature-grid">
          <a
            v-for="feature in features"
            :key="feature.id"
            class="feature-card tombo-corners"
            :href="withBase(feature.href)"
          >
            <span class="feature-id">{{ feature.id }}</span>
            <strong>{{ feature.title }}</strong>
            <p>{{ feature.detail }}</p>
            <span class="feature-meta">{{ feature.meta }}</span>
          </a>
        </div>
      </section>

      <section class="home-section" aria-labelledby="quickstart-title">
        <header class="section-heading">
          <div>
            <div class="tombo-dim"></div>
            <p class="tombo-dimlab">section / 02 · five-minute path</p>
          </div>
          <h2 id="quickstart-title" class="tombo-sec">From contract to evidence</h2>
          <p>
            Install Gesso, choose the path matching your test stack, and reach one passing
            contract assertion with a coverage report.
          </p>
        </header>

        <div class="quickstart-layout">
          <nav class="quickstart-list" aria-label="Gesso quickstarts">
            <header><span>QUICKSTART / PICK ONE</span><span>04</span></header>
            <a
              v-for="(quickstart, index) in quickstarts"
              :key="quickstart.id"
              :class="{ 'tombo-current': index === 0 }"
              :href="withBase(quickstart.href)"
            >
              <span>{{ quickstart.id }}</span>
              <strong>{{ quickstart.name }}</strong>
              <i aria-hidden="true">→</i>
            </a>
          </nav>

          <div class="quickstart-proof">
            <figure class="tombo-code">
              <figcaption><span>SHELL — INSTALL &amp; RUN</span><span>02 STEPS</span></figcaption>
              <pre><code><span class="code-line"><i>001</i><b>$</b> composer require --dev &quot;studio-design/gesso:^2.0&quot;</span>
<span class="code-line"><i>002</i><b>$</b> vendor/bin/phpunit</span>
<span class="code-line code-comment"><i>003</i># coverage: method · path · status · content-type</span></code></pre>
            </figure>
            <aside class="tombo-callout">
              <b>NOTE — 001</b>Every quickstart is installed and executed in this repository's CI.
            </aside>
          </div>
        </div>
      </section>

      <section class="release-panel tombo-corners" aria-labelledby="release-title">
        <div>
          <p class="tombo-kicker">GESSO 2 / REV {{ docsVersion }}</p>
          <h2 id="release-title" class="tombo-display">A clearer identity.<br>A stricter contract.</h2>
        </div>
        <div class="release-copy">
          <p>
            Gesso 2 brings the <code>Studio\Gesso</code> namespace, one <code>gesso</code>
            CLI, modern runtime support, and explicit compatibility boundaries.
          </p>
          <div>
            <a class="tombo-btn tombo-btn--primary" :href="withBase('/migration/v2')">
              Read the migration guide
            </a>
            <a class="release-text-link" :href="withBase('/supported-features')">
              Inspect supported features <span aria-hidden="true">→</span>
            </a>
          </div>
        </div>
      </section>
    </div>
  </main>
</template>

<style scoped>
.tombo-home {
  min-width: 0;
  overflow: hidden;
  background: var(--tombo-paper);
  color: var(--tombo-ink);
}

.tombo-home .tombo-display {
  font-family: var(--tombo-font-display);
  font-size: var(--tombo-text-display);
  font-weight: 700;
  line-height: 1.1;
  letter-spacing: -0.015em;
}

.home-shell {
  min-width: 0;
}

.tombo-hero {
  position: relative;
  padding-block: clamp(4.75rem, 9vw, 7.5rem);
}

.frame-mark {
  position: absolute;
  width: 14px;
  height: 14px;
  border: 0 solid var(--tombo-ink);
  opacity: 0.72;
}

.frame-mark-tl { top: 20px; left: 20px; border-top-width: 1.5px; border-left-width: 1.5px; }
.frame-mark-tr { top: 20px; right: 20px; border-top-width: 1.5px; border-right-width: 1.5px; }
.frame-mark-bl { bottom: 20px; left: 20px; border-bottom-width: 1.5px; border-left-width: 1.5px; }
.frame-mark-br { right: 20px; bottom: 20px; border-right-width: 1.5px; border-bottom-width: 1.5px; }

.hero-grid {
  display: grid;
  grid-template-columns: minmax(0, 0.96fr) minmax(420px, 1.04fr);
  gap: clamp(2rem, 3vw, 3rem);
  align-items: center;
}

.hero-copy h1 {
  max-width: 13ch;
  margin-top: 1.5rem;
}

.hero-copy h1 small {
  display: block;
  margin-top: 0.9rem;
  color: var(--tombo-green);
  font-family: var(--tombo-font-body);
  font-size: var(--tombo-text-h2);
  font-weight: 700;
  line-height: var(--tombo-lh-h2);
  letter-spacing: 0;
}

.headline-measure {
  width: min(100%, 430px);
  margin-top: 1.5rem;
}

.headline-dim-label {
  display: flex;
  gap: 0.6em;
  white-space: nowrap;
}

.headline-dim-spec::before {
  content: "· ";
}

.hero-lead {
  max-width: 60ch;
  margin-top: 1.25rem;
  color: var(--tombo-ink-sub);
  font-size: var(--tombo-text-body);
  line-height: var(--tombo-lh-body);
}

.hero-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.75rem;
  align-items: center;
  margin-top: 1.75rem;
}

.hero-actions .tombo-btn {
  min-height: 48px;
  text-decoration: none;
}

.tombo-home a.tombo-btn--primary {
  color: var(--tombo-on-green);
}

.tombo-home a.tombo-btn--primary:hover {
  color: var(--tombo-paper);
}

.hero-license {
  color: var(--tombo-ink-faint);
  font-family: var(--tombo-font-mono);
  font-size: var(--tombo-text-label);
  letter-spacing: 0.08em;
}

.coverage-instrument {
  margin: 1.25rem 0 0;
  padding: 1.6rem;
  border: 1px solid var(--tombo-hairline);
  background: var(--tombo-surface);
}

.coverage-instrument figcaption {
  position: absolute;
  top: -1.6rem;
  left: 0;
}

.instrument-logo {
  position: absolute;
  top: 1.25rem;
  right: 1.25rem;
  width: 28px;
  height: 28px;
}

.instrument-label {
  color: var(--tombo-ink-sub);
  font-family: var(--tombo-font-mono);
  font-size: var(--tombo-text-label);
  font-weight: 500;
  letter-spacing: 0.14em;
}

.instrument-value {
  margin-top: 0.35rem;
  font-family: var(--tombo-font-mono);
  font-size: clamp(2.4rem, 6vw, 4rem);
  font-weight: 600;
  line-height: 1.1;
}

.instrument-value small {
  color: var(--tombo-ink-faint);
  font-size: var(--tombo-text-body);
  font-weight: 400;
}

.coverage-instrument .tombo-ruler {
  margin-top: 0.75rem;
}

.coverage-rows {
  margin-top: 1.1rem;
  border-top: 1px solid var(--tombo-hairline);
  list-style: none;
}

.coverage-rows li {
  display: flex;
  gap: 1rem;
  justify-content: space-between;
  padding-block: 0.45rem;
  border-bottom: 1px solid var(--tombo-hairline);
  color: var(--tombo-ink-sub);
  font-family: var(--tombo-font-mono);
  font-size: var(--tombo-text-label);
  line-height: var(--tombo-lh-label);
}

.coverage-rows code {
  min-width: 0;
  overflow-wrap: anywhere;
}

.coverage-rows span {
  color: var(--tombo-ink);
  font-weight: 500;
}

.coverage-rows .is-missing {
  color: var(--tombo-error);
}

.instrument-foot {
  margin-top: 0.85rem;
  color: var(--tombo-ink-faint);
  font-family: var(--tombo-font-mono);
  font-size: var(--tombo-text-label);
  line-height: var(--tombo-lh-label);
  letter-spacing: 0.06em;
}

.home-sections {
  padding-block: clamp(4rem, 8vw, 7rem);
}

.home-section + .home-section {
  margin-top: clamp(4.5rem, 9vw, 7.5rem);
}

.section-heading > div:first-child {
  max-width: 100%;
}

.section-heading h2 {
  margin-top: 1.35rem;
  color: var(--tombo-ink);
  font-size: var(--tombo-text-h2);
  line-height: var(--tombo-lh-h2);
}

.section-heading > p {
  max-width: 62ch;
  margin-top: 0.8rem;
  color: var(--tombo-ink-sub);
}

.feature-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 1.1rem;
  margin-top: 1.6rem;
}

.feature-card {
  display: flex;
  min-height: 230px;
  flex-direction: column;
  padding: 1.5rem;
  border: 1px solid var(--tombo-hairline);
  background: var(--tombo-surface);
  color: var(--tombo-ink);
  text-decoration: none;
}

.feature-card:hover {
  border-color: var(--tombo-green);
}

.feature-id,
.feature-meta {
  color: var(--tombo-green);
  font-family: var(--tombo-font-mono);
  font-size: var(--tombo-text-label);
  line-height: var(--tombo-lh-label);
  letter-spacing: 0.1em;
}

.feature-card strong {
  margin-top: 0.6rem;
  font-size: var(--tombo-text-h3);
  line-height: var(--tombo-lh-h3);
}

.feature-card p {
  margin-top: 0.45rem;
  color: var(--tombo-ink-sub);
  font-size: var(--tombo-text-ui);
  line-height: var(--tombo-lh-ui);
}

.feature-meta {
  margin-top: auto;
  padding-top: 0.9rem;
  border-top: 1px solid var(--tombo-hairline);
  color: var(--tombo-ink-faint);
}

.quickstart-layout {
  display: grid;
  min-width: 0;
  grid-template-columns: minmax(260px, 0.78fr) minmax(0, 1.22fr);
  gap: 2rem;
  align-items: start;
  margin-top: 1.6rem;
}

.quickstart-list {
  min-width: 0;
  border: 1px solid var(--tombo-hairline);
  background: var(--tombo-surface);
}

.quickstart-list header,
.quickstart-list a {
  display: flex;
  gap: 0.9rem;
  align-items: center;
  min-height: 48px;
  padding-inline: 1.2rem;
  border-bottom: 1px solid var(--tombo-hairline);
}

.quickstart-list header {
  justify-content: space-between;
  color: var(--tombo-ink-faint);
  font-family: var(--tombo-font-mono);
  font-size: var(--tombo-text-label);
  letter-spacing: 0.1em;
}

.quickstart-list a {
  position: relative;
  color: var(--tombo-ink);
  font-size: var(--tombo-text-ui);
  text-decoration: none;
}

.quickstart-list a:last-child {
  border-bottom: 0;
}

.quickstart-list a:hover {
  background: var(--tombo-paper);
}

.quickstart-list a > span {
  color: var(--tombo-green);
  font-family: var(--tombo-font-mono);
  font-size: var(--tombo-text-label);
}

.quickstart-list a > i {
  margin-left: auto;
  color: var(--tombo-ink-faint);
  font-family: var(--tombo-font-mono);
  font-style: normal;
}

.quickstart-list .tombo-current {
  padding-left: 2.2rem;
}

.quickstart-list .tombo-current::before {
  left: 0.75rem;
}

.quickstart-proof {
  min-width: 0;
}

.quickstart-proof .tombo-code {
  width: 100%;
  max-width: 100%;
}

.quickstart-proof .tombo-code pre {
  max-width: 100%;
  margin: 0;
}

.code-line {
  display: block;
  white-space: pre;
}

.code-line i {
  display: inline-block;
  width: 2.5rem;
  color: var(--tombo-code-faint);
  font-style: normal;
  user-select: none;
}

.code-line b {
  color: var(--tombo-code-green);
  font-weight: 400;
}

.code-comment {
  color: var(--tombo-code-faint);
}

.quickstart-proof .tombo-callout {
  margin-top: 1rem;
}

.release-panel {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(0, 0.8fr);
  gap: clamp(2rem, 6vw, 5rem);
  margin-top: clamp(4.5rem, 9vw, 7.5rem);
  padding: clamp(2rem, 5vw, 4rem);
  border: 1px solid var(--tombo-hairline);
  background: var(--tombo-surface);
}

.release-panel h2 {
  margin-top: 1rem;
  font-size: clamp(2.2rem, 5vw, 3.8rem);
}

.release-copy {
  align-self: end;
}

.release-copy > p {
  color: var(--tombo-ink-sub);
}

.release-copy > div {
  display: flex;
  flex-wrap: wrap;
  gap: 0.75rem 1.2rem;
  align-items: center;
  margin-top: 1.4rem;
}

.release-copy .tombo-btn {
  min-height: 48px;
  text-decoration: none;
}

.release-text-link {
  min-height: 44px;
  padding-block: 0.65rem;
}

@media (max-width: 1024px) {
  .hero-grid {
    grid-template-columns: 1fr;
  }

  .coverage-instrument {
    margin-top: 1.5rem;
  }
}

@media (max-width: 900px) {
  .release-panel {
    grid-template-columns: 1fr;
  }

  .feature-grid {
    grid-template-columns: 1fr 1fr;
  }
}

@media (max-width: 680px) {
  .tombo-hero {
    padding-block: 4.5rem;
  }

  .hero-grid {
    gap: 3.75rem;
  }

  .feature-grid,
  .quickstart-layout {
    grid-template-columns: 1fr;
  }

  .feature-card {
    min-height: 0;
  }

  .frame-mark {
    display: none;
  }
}

@media (max-width: 480px) {
  .headline-dim-label {
    display: grid;
    gap: 0.1rem;
    white-space: normal;
  }

  .headline-dim-spec::before {
    content: "";
  }

  .hero-actions {
    align-items: stretch;
    flex-direction: column;
  }

  .hero-actions .tombo-btn {
    width: 100%;
    text-align: center;
  }

  .coverage-instrument {
    padding: 1.25rem;
  }

  .coverage-rows li {
    align-items: flex-start;
    flex-direction: column;
    gap: 0.2rem;
  }

  .release-panel {
    padding: 1.5rem;
  }
}
</style>
