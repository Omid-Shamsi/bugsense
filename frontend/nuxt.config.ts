// https://nuxt.com/docs/api/configuration/nuxt-config
export default defineNuxtConfig({
  compatibilityDate: '2025-07-15',

  ssr: false,

  devtools: {
    enabled: true
  },

  runtimeConfig: {
    public: {
      apiBase: process.env.NUXT_PUBLIC_API_BASE || 'http://localhost:8000/api/v1',
      backendOrigin: process.env.NUXT_PUBLIC_BACKEND_ORIGIN || 'http://localhost:8000'
    }
  },

  css: ['~/assets/css/main.css']
})
