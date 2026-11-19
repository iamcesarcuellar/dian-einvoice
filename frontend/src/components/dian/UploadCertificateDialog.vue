<script setup lang="ts">
import type { SigningCertificate } from '@/api/types'
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { signingCertificateApi } from '@/api/dian'
import FormError from '@/components/FormError.vue'
import FormField from '@/components/FormField.vue'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { useFormMutation } from '@/composables/useFormMutation'

/**
 * Uploads a .p12 or .pfx signing certificate with its password. The API checks that it opens and
 * holds an RSA private key with its certificate; the key and the password never come back.
 */
const emit = defineEmits<{
  uploaded: [certificate: SigningCertificate]
}>()

const open = defineModel<boolean>('open', { required: true })

const { t } = useI18n()

const file = ref<File | null>(null)
const password = ref('')
const fileInput = ref<HTMLInputElement | null>(null)

function chooseFile(event: Event): void {
  const input = event.target as HTMLInputElement

  file.value = input.files?.[0] ?? null
}

const { submit, isPending, error, errors, reset } = useFormMutation({
  mutationFn: () => signingCertificateApi.upload(file.value as File, password.value),
  success: t('settings.dian.certificate.uploaded'),
  onSuccess: (certificate) => {
    emit('uploaded', certificate)
    open.value = false
  },
})

function send(): void {
  if (file.value) {
    submit()
  }
}

watch(open, (isOpen) => {
  if (isOpen) {
    file.value = null
    password.value = ''
    reset()

    if (fileInput.value) {
      fileInput.value.value = ''
    }
  }
})
</script>

<template>
  <Dialog v-model:open="open">
    <DialogContent class="sm:max-w-lg">
      <DialogHeader>
        <DialogTitle>{{ t('settings.dian.certificate.uploadTitle') }}</DialogTitle>
        <DialogDescription>{{ t('settings.dian.certificate.uploadDescription') }}</DialogDescription>
      </DialogHeader>

      <form id="certificate-form" class="grid gap-4" novalidate @submit.prevent="send">
        <FormError :error="error" />

        <FormField id="certificate-file" :label="t('settings.dian.certificate.file')" :hint="t('settings.dian.certificate.fileHint')" :error="errors.file?.[0]">
          <input
            id="certificate-file"
            ref="fileInput"
            type="file"
            accept=".p12,.pfx,application/x-pkcs12"
            class="h-9 w-full min-w-0 rounded-md border border-input bg-transparent px-2.5 py-1 text-base shadow-xs outline-none file:mr-3 file:inline-flex file:h-7 file:border-0 file:bg-transparent file:text-sm file:font-medium file:text-foreground focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50 aria-invalid:border-destructive md:text-sm dark:bg-input/30"
            :aria-invalid="!!errors.file"
            :disabled="isPending"
            @change="chooseFile"
          >
        </FormField>

        <FormField id="certificate-password" :label="t('settings.dian.certificate.password')" :hint="t('settings.dian.certificate.passwordHint')" :error="errors.password?.[0]">
          <Input
            id="certificate-password"
            v-model="password"
            type="password"
            autocomplete="off"
            :disabled="isPending"
            :aria-invalid="!!errors.password"
          />
        </FormField>
      </form>

      <DialogFooter>
        <Button type="button" variant="outline" @click="open = false">{{ t('common.cancel') }}</Button>
        <Button type="submit" form="certificate-form" :disabled="isPending || !file">
          {{ isPending ? t('settings.dian.certificate.uploading') : t('settings.dian.certificate.upload') }}
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
