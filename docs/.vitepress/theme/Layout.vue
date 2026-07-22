<script setup lang="ts">
import DefaultTheme from 'vitepress/theme'

declare const __DOCS_VERSION__: string

const { Layout } = DefaultTheme
const docsVersion = __DOCS_VERSION__
</script>

<template>
  <Layout>
    <template #layout-top>
      <div class="version-banner">
        <span class="tombo-kicker">Documentation version: <strong>{{ docsVersion }}</strong></span>
        <span v-if="docsVersion === 'next'" class="version-banner-note">development documentation</span>
      </div>
    </template>
    <template #layout-bottom>
      <footer class="tombo-site-footer tombo-shell" aria-label="Site design information">
        <span class="tombo-logo" aria-hidden="true">
          <i class="h"></i><i class="b"></i><i class="w1"></i><i class="w2"></i><i class="t"></i>
        </span>
        <p>
          GESSO / STUDIO-DESIGN / MIT LICENSE<br>
          SET IN TOMBO — WADAKATU DESIGN SYSTEM
        </p>
      </footer>
    </template>
  </Layout>
</template>
