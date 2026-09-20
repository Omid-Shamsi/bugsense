// https://nuxt.com/docs/api/configuration/nuxt-config
export default defineNuxtConfig({
  compatibilityDate: '2025-07-15',

  ssr: false,

  modules: ['@nuxt/ui'],

  devtools: {
    enabled: true
  },

  components: [{ path: '~/components', pathPrefix: false }],

  app: {
    head: {
      htmlAttrs: { lang: 'fa', dir: 'rtl' }
    }
  },

  colorMode: {
    preference: 'dark',
    fallback: 'dark'
  },

  ui: {
    fonts: false
  },

  runtimeConfig: {
    public: {
      apiBase: process.env.NUXT_PUBLIC_API_BASE || 'http://localhost:8000/api/v1',
      backendOrigin: process.env.NUXT_PUBLIC_BACKEND_ORIGIN || 'http://localhost:8000'
    }
  },

  css: ['~/assets/css/main.css']
})
