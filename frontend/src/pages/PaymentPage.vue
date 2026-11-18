<script setup lang="ts">
import { InfoIcon } from '@lucide/vue'
import { useQuery } from '@tanstack/vue-query'
import { computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { paymentsApi } from '@/api/resources'
import PaymentEditor from '@/components/payment/PaymentEditor.vue'
import PaymentView from '@/components/payment/PaymentView.vue'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { useSession } from '@/composables/useSession'
import { tenantKey } from '@/lib/query'
import { useSessionStore } from '@/stores/session'

/** /payments/new records a payment; /payments/:id shows one. */
const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const session = useSessionStore()
const { can } = useSession()

const paymentId = computed(() => (typeof route.params.id === 'string' ? route.params.id : null))

const { data: payment } = useQuery({
  queryKey: computed(() => tenantKey(session.currentCompanyId, 'payments', 'detail', paymentId.value)),
  queryFn: () => paymentsApi.get(paymentId.value as string),
  enabled: computed(() => paymentId.value !== null),
})

// Another company has other payments: go back to the list.
watch(() => session.currentCompanyId, () => {
  void router.push({ name: 'payments' })
})
</script>

<template>
  <template v-if="paymentId === null">
    <PaymentEditor v-if="can('record-payments')" :key="String(route.fullPath)" />
    <Alert v-else>
      <InfoIcon />
      <AlertDescription>{{ t('payments.editor.readOnly') }}</AlertDescription>
    </Alert>
  </template>
  <p v-else-if="!payment" class="text-muted-foreground">{{ t('common.loading') }}</p>
  <PaymentView v-else :payment="payment" />
</template>
