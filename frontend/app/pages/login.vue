<script setup lang="ts">
import { ApiError } from '~/plugins/api.client'

definePageMeta({ layout: false })

const session = useSession()
const email = ref('')
const password = ref('')
const submitting = ref(false)
const formError = ref('')
const fieldErrors = ref<Record<string, string[]>>({})

await session.refresh().catch(() => undefined)

async function submit() {
  if (submitting.value) return

  formError.value = ''
  fieldErrors.value = {}
  submitting.value = true

  try {
    await session.login(email.value, password.value)
  } catch (error) {
    if (error instanceof ApiError) {
      fieldErrors.value = error.fieldErrors
      formError.value = error.kind === 'csrf'
        ? 'Security check expired. Please try again.'
        : error.kind === 'network'
          ? 'Cannot reach BugSense. Check connection and try again.'
          : error.problem?.detail || 'Unable to sign in. Please try again.'
    } else {
      formError.value = 'Unable to sign in. Please try again.'
    }
  } finally {
    submitting.value = false
  }
}

async function signOut() {
  formError.value = ''
  try {
    await session.logout()
  } catch (error) {
    formError.value = error instanceof ApiError && error.kind === 'csrf'
      ? 'Security check expired. Please try again.'
      : 'Unable to sign out. Please try again.'
  }
}
</script>

<template>
  <main class="auth-page">
    <section class="auth-card" aria-labelledby="login-title">
      <div class="brand" aria-label="BugSense">
        <span class="brand-mark" aria-hidden="true">B</span>
        <span>BugSense</span>
      </div>

      <template v-if="session.isAuthenticated.value && session.user.value">
        <div class="auth-heading">
          <p class="eyebrow">Session active</p>
          <h1 id="login-title">You are signed in</h1>
          <p>{{ session.user.value.display_name || session.user.value.email }}</p>
        </div>
        <button class="button button-secondary" type="button" @click="signOut">Sign out</button>
      </template>

      <template v-else>
        <div class="auth-heading">
          <p class="eyebrow">Workspace access</p>
          <h1 id="login-title">Sign in to BugSense</h1>
          <p>Use your provisioned account to continue.</p>
        </div>

        <form novalidate @submit.prevent="submit">
          <div v-if="formError" class="form-error" role="alert">{{ formError }}</div>

          <div class="field">
            <label for="email">Email address</label>
            <input id="email" v-model.trim="email" :disabled="submitting" autocomplete="email" inputmode="email" name="email" type="email" required aria-describedby="email-error">
            <p v-if="fieldErrors.email?.[0]" id="email-error" class="field-error">{{ fieldErrors.email[0] }}</p>
          </div>

          <div class="field">
            <label for="password">Password</label>
            <input id="password" v-model="password" :disabled="submitting" autocomplete="current-password" name="password" type="password" required aria-describedby="password-error">
            <p v-if="fieldErrors.password?.[0]" id="password-error" class="field-error">{{ fieldErrors.password[0] }}</p>
          </div>

          <button class="button button-primary" :disabled="submitting" type="submit">
            {{ submitting ? 'Signing in…' : 'Sign in' }}
          </button>
        </form>
      </template>
    </section>
  </main>
</template>
