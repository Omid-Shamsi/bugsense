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
        ? 'مهلت بررسی امنیتی پایان یافته است. دوباره تلاش کنید.'
        : error.kind === 'network'
          ? 'ارتباط با BugSense برقرار نشد. اتصال را بررسی و دوباره تلاش کنید.'
          : error.problem?.detail || 'ورود امکان‌پذیر نبود. دوباره تلاش کنید.'
    } else {
      formError.value = 'ورود امکان‌پذیر نبود. دوباره تلاش کنید.'
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
      ? 'مهلت بررسی امنیتی پایان یافته است. دوباره تلاش کنید.'
      : 'خروج امکان‌پذیر نبود. دوباره تلاش کنید.'
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
          <p class="eyebrow">نشست فعال</p>
          <h1 id="login-title">شما وارد شده‌اید</h1>
          <p :dir="session.user.value.display_name ? undefined : 'ltr'">{{ session.user.value.display_name || session.user.value.email }}</p>
        </div>
        <button class="button button-secondary" type="button" @click="signOut">خروج</button>
      </template>

      <template v-else>
        <div class="auth-heading">
          <p class="eyebrow">دسترسی به فضای کاری</p>
          <h1 id="login-title">ورود به BugSense</h1>
          <p>برای ادامه از حساب کاربری خود استفاده کنید.</p>
        </div>

        <form novalidate @submit.prevent="submit">
          <div v-if="formError" class="form-error" role="alert">{{ formError }}</div>

          <div class="field">
            <label for="email">ایمیل</label>
            <input id="email" v-model.trim="email" dir="ltr" :disabled="submitting" autocomplete="email" inputmode="email" name="email" type="email" required aria-describedby="email-error">
            <p v-if="fieldErrors.email?.[0]" id="email-error" class="field-error">{{ fieldErrors.email[0] }}</p>
          </div>

          <div class="field">
            <label for="password">رمز عبور</label>
            <input id="password" v-model="password" :disabled="submitting" autocomplete="current-password" name="password" type="password" required aria-describedby="password-error">
            <p v-if="fieldErrors.password?.[0]" id="password-error" class="field-error">{{ fieldErrors.password[0] }}</p>
          </div>

          <button class="button button-primary" :disabled="submitting" type="submit">
            {{ submitting ? 'در حال ورود…' : 'ورود' }}
          </button>
        </form>
      </template>
    </section>
  </main>
</template>
