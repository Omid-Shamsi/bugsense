<script setup lang="ts">
import { ApiError } from '~/plugins/api.client'

definePageMeta({ layout: false })

const session = useSession()
const api = useApi()
const route = useRoute()
const email = ref('')
const password = ref('')
const submitting = ref(false)
const recoveringIdentity = ref(false)
const formError = ref('')
const fieldErrors = ref<Record<string, string[]>>({})
const probeState = ref<'loading' | 'ready' | 'error'>('loading')
const probeError = ref('')
const passwordVisible = ref(false)

function errorMessage(error: unknown, fallback: string) {
  if (!(error instanceof ApiError)) return fallback
  if (error.kind === 'csrf') return 'مهلت بررسی امنیتی پایان یافته است. دوباره تلاش کنید.'
  if (error.kind === 'network') return 'ارتباط با BugSense برقرار نشد. اتصال را بررسی و دوباره تلاش کنید.'
  return fallback
}

function safeReturnPath(value: unknown) {
  if (typeof value !== 'string' || !value.startsWith('/') || value.startsWith('//') || value.includes('\\')) return '/dashboard'

  try {
    const parsed = new URL(value, 'https://bugsense.invalid')
    if (parsed.origin !== 'https://bugsense.invalid' || parsed.pathname === '/login') return '/dashboard'
    return `${parsed.pathname}${parsed.search}${parsed.hash}`
  } catch {
    return '/dashboard'
  }
}

const returnPath = computed(() => safeReturnPath(route.query.returnTo))

function focusField(id: string) {
  nextTick(() => document.getElementById(id)?.focus())
}

function clearFieldError(field: 'email' | 'password') {
  if (fieldErrors.value[field]) {
    const next = { ...fieldErrors.value }
    delete next[field]
    fieldErrors.value = next
  }
  formError.value = ''
}

function validate() {
  const errors: Record<string, string[]> = {}
  const submittedEmail = email.value.trim()

  if (!submittedEmail) errors.email = ['ایمیل را وارد کنید.']
  else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(submittedEmail)) errors.email = ['ایمیل معتبر وارد کنید.']
  if (!password.value) errors.password = ['رمز عبور را وارد کنید.']

  fieldErrors.value = errors
  const firstField = errors.email ? 'login-email' : errors.password ? 'login-password' : undefined
  if (firstField) focusField(firstField)
  return !firstField
}

async function redirectAfterLogin() {
  await navigateTo(returnPath.value, { replace: true })
}

async function resolveInitialSession() {
  probeState.value = 'loading'
  probeError.value = ''

  try {
    await session.refresh()
    if (session.isAuthenticated.value) {
      await navigateTo('/dashboard', { replace: true })
      return
    }
    probeState.value = 'ready'
  } catch (error) {
    probeError.value = errorMessage(error, 'بررسی نشست انجام نشد. دوباره تلاش کنید.')
    probeState.value = 'error'
  }
}

await resolveInitialSession()

async function submit() {
  if (submitting.value || !validate()) return

  formError.value = ''
  submitting.value = true
  let sessionCreated = false

  try {
    await api.login(email.value.trim(), password.value)
    sessionCreated = true
    await session.refresh()
    if (!session.isAuthenticated.value) throw new Error('Current user was not loaded')
    await redirectAfterLogin()
  } catch (error) {
    if (sessionCreated) {
      recoveringIdentity.value = true
      formError.value = 'ورود انجام شد، اما اطلاعات حساب بارگیری نشد.'
      return
    }

    if (error instanceof ApiError && error.kind === 'validation') {
      if (error.fieldErrors.password) {
        fieldErrors.value = { password: ['رمز عبور را وارد کنید.'] }
        focusField('login-password')
        return
      }

      password.value = ''
      formError.value = 'ایمیل یا رمز عبور صحیح نیست.'
      focusField('login-email')
      return
    }

    formError.value = errorMessage(error, 'ورود انجام نشد. دوباره تلاش کنید.')
  } finally {
    submitting.value = false
  }
}

async function retryIdentity() {
  if (submitting.value) return
  submitting.value = true
  try {
    await session.refresh()
    if (!session.isAuthenticated.value) throw new Error('Current user was not loaded')
    await redirectAfterLogin()
  } catch (error) {
    formError.value = errorMessage(error, 'ورود انجام شد، اما اطلاعات حساب بارگیری نشد.')
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <main class="auth-page">
    <section class="auth-card" aria-labelledby="login-title">
      <div class="brand" aria-label="BugSense" dir="ltr">
        <span class="brand-mark" aria-hidden="true">B</span>
        <span>BugSense</span>
      </div>

      <div v-if="probeState === 'loading'" class="login-probe" role="status">
        <USkeleton class="h-7 w-40" />
        <USkeleton class="mt-3 h-4 w-full" />
        <span class="sr-only">در حال بررسی نشست…</span>
      </div>

      <div v-else-if="probeState === 'error'" class="login-probe login-probe--error" role="alert">
        <UAlert color="error" variant="subtle" :description="probeError" />
        <UButton block :loading="submitting" @click="resolveInitialSession">تلاش دوباره</UButton>
      </div>

      <template v-else-if="recoveringIdentity">
        <div class="auth-heading">
          <h1 id="login-title">تکمیل ورود</h1>
          <p>نشست شما ایجاد شده است. برای ورود به فضای کاری، اطلاعات حساب را بارگیری کنید.</p>
        </div>
        <UAlert color="warning" variant="subtle" :description="formError" role="alert" />
        <UButton block :loading="submitting" @click="retryIdentity">تلاش دوباره</UButton>
      </template>

      <template v-else>
        <div class="auth-heading">
          <h1 id="login-title">ورود به <bdi dir="ltr">BugSense</bdi></h1>
          <p>برای ادامه، ایمیل و رمز عبور حساب خود را وارد کنید.</p>
        </div>

        <UAlert v-if="route.query.returnTo" color="info" variant="subtle" description="برای ادامه وارد شوید." class="mb-5" />
        <form class="login-form" novalidate @submit.prevent="submit">
          <UAlert v-if="formError" color="error" variant="subtle" :description="formError" role="alert" />
          <UFormField label="ایمیل" required :error="fieldErrors.email?.[0]">
            <UInput id="login-email" v-model="email" name="email" type="email" dir="ltr" autocomplete="email" inputmode="email" autocapitalize="none" spellcheck="false" :disabled="submitting" :aria-invalid="!!fieldErrors.email" class="w-full" @update:model-value="clearFieldError('email')" />
          </UFormField>
          <UFormField label="رمز عبور" required :error="fieldErrors.password?.[0]">
            <UInput id="login-password" v-model="password" name="password" :type="passwordVisible ? 'text' : 'password'" dir="ltr" autocomplete="current-password" :disabled="submitting" :aria-invalid="!!fieldErrors.password" class="w-full login-password-input" @update:model-value="clearFieldError('password')">
              <template #trailing>
                <UButton :icon="passwordVisible ? 'i-lucide-eye-off' : 'i-lucide-eye'" color="neutral" variant="link" size="sm" :disabled="submitting" :aria-label="passwordVisible ? 'پنهان‌کردن رمز عبور' : 'نمایش رمز عبور'" :title="passwordVisible ? 'پنهان‌کردن رمز عبور' : 'نمایش رمز عبور'" @click.prevent="passwordVisible = !passwordVisible" />
              </template>
            </UInput>
          </UFormField>
          <UButton block type="submit" :loading="submitting" :disabled="submitting">{{ submitting ? 'در حال ورود…' : 'ورود' }}</UButton>
        </form>
      </template>
    </section>
  </main>
</template>

<style scoped>
.auth-page { background: #0b0f14; min-height: 100dvh; }
.auth-card { box-shadow: none; }
.auth-heading { margin: 28px 0 24px; }
.login-form { gap: 18px; }
.login-probe { margin: 28px 0 4px; min-height: 280px; }
.login-probe--error { display: grid; gap: 16px; min-height: auto; }
.login-password-input :deep(input) { text-align: left; unicode-bidi: isolate; }

@media (max-width: 480px) {
  .auth-card { background: transparent; border-color: transparent; }
}
</style>
