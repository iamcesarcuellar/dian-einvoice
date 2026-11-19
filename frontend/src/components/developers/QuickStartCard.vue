<script setup lang="ts">
import { ExternalLinkIcon } from '@lucide/vue'
import { useQuery } from '@tanstack/vue-query'
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { contactsApi, itemsApi } from '@/api/resources'
import FormSection from '@/components/FormSection.vue'
import { Button } from '@/components/ui/button'
import { tenantKey } from '@/lib/query'
import { useSessionStore } from '@/stores/session'
import CodeBlock from './CodeBlock.vue'

/**
 * Quick start: a curl session that creates an invoice and issues it with an Idempotency-Key, with a
 * customer and an item of this company already filled in; how to verify a webhook signature; and
 * the link to the API reference.
 */
const { t } = useI18n()
const session = useSessionStore()

const { data: contacts } = useQuery({
  queryKey: computed(() => tenantKey(session.currentCompanyId, 'contacts', 'quick-start')),
  // The example needs one customer: the first two active contacts always include one besides the final consumer.
  queryFn: () => contactsApi.list({ active: 1, per_page: 2 }),
})

const { data: items } = useQuery({
  queryKey: computed(() => tenantKey(session.currentCompanyId, 'items', 'quick-start')),
  queryFn: () => itemsApi.list({ per_page: 1 }),
})

const contactId = computed(() => (contacts.value?.data.find((contact) => !contact.is_system && contact.is_active) ?? contacts.value?.data[0])?.id ?? 'CONTACT_ID')
const itemId = computed(() => items.value?.data[0]?.id ?? 'ITEM_ID')
const idempotencyKey = ref(crypto.randomUUID())
const apiBase = 'http://localhost:8600/api/v1'

const curl = computed(() => [
  `# ${t('developers.quickStart.comments.key')}`,
  `export API_KEY='dei_...'`,
  `export API=${apiBase}`,
  '',
  `# ${t('developers.quickStart.comments.draft')}`,
  `INVOICE_ID=$(curl -s "$API/invoices" \\`,
  `  -H "Authorization: Bearer $API_KEY" \\`,
  `  -H 'Accept: application/json' -H 'Content-Type: application/json' \\`,
  `  -d '{"contact_id": "${contactId.value}", "payment_form": "1", "payment_means_code": "10", "lines": [{"item_id": "${itemId.value}", "quantity": "1"}]}' \\`,
  `  | grep -o '"id":"[^"]*"' | head -1 | cut -d'"' -f4)`,
  '',
  `# ${t('developers.quickStart.comments.issue')}`,
  `curl -s -X POST "$API/invoices/$INVOICE_ID/issue" \\`,
  `  -H "Authorization: Bearer $API_KEY" \\`,
  `  -H 'Accept: application/json' \\`,
  `  -H 'Idempotency-Key: ${idempotencyKey.value}'`,
  '',
  `# ${t('developers.quickStart.comments.status')}`,
  `curl -s "$API/invoices/$INVOICE_ID" -H "Authorization: Bearer $API_KEY" -H 'Accept: application/json'`,
].join('\n'))

const node = computed(() => [
  `import { createHmac, timingSafeEqual } from 'node:crypto'`,
  '',
  `// ${t('developers.quickStart.comments.verify')}`,
  `export function verifyWebhook(secret, headers, rawBody) {`,
  `  const id = headers['webhook-id']`,
  `  const timestamp = headers['webhook-timestamp']`,
  '',
  `  // ${t('developers.quickStart.comments.tolerance')}`,
  `  if (!id || Math.abs(Date.now() / 1000 - Number(timestamp)) > 300) return false`,
  '',
  `  const key = Buffer.from(secret.replace(/^whsec_/, ''), 'base64')`,
  `  const expected = createHmac('sha256', key).update(\`\${id}.\${timestamp}.\${rawBody}\`).digest()`,
  '',
  `  return (headers['webhook-signature'] ?? '').split(' ').some((signature) => {`,
  `    const [version, value = ''] = signature.split(',')`,
  `    const given = Buffer.from(value, 'base64')`,
  '',
  `    return version === 'v1' && given.length === expected.length && timingSafeEqual(given, expected)`,
  `  })`,
  `}`,
].join('\n'))

const php = computed(() => [
  `<?php`,
  '',
  `// ${t('developers.quickStart.comments.verify')}`,
  `function verifyWebhook(string $secret, array $headers, string $rawBody): bool`,
  `{`,
  `    $id = $headers['webhook-id'] ?? '';`,
  `    $timestamp = (int) ($headers['webhook-timestamp'] ?? 0);`,
  '',
  `    if ($id === '' || abs(time() - $timestamp) > 300) {`,
  `        return false;`,
  `    }`,
  '',
  `    $key = base64_decode(substr($secret, strlen('whsec_')));`,
  `    $expected = 'v1,'.base64_encode(hash_hmac('sha256', "{$id}.{$timestamp}.{$rawBody}", $key, true));`,
  '',
  `    foreach (explode(' ', $headers['webhook-signature'] ?? '') as $signature) {`,
  `        if (hash_equals($expected, $signature)) {`,
  `            return true;`,
  `        }`,
  `    }`,
  '',
  `    return false;`,
  `}`,
].join('\n'))

const language = ref<'node' | 'php'>('node')
</script>

<template>
  <FormSection :title="t('developers.quickStart.title')" :description="t('developers.quickStart.description')">
    <div class="grid gap-6">
      <div class="grid gap-2">
        <h3 class="text-sm font-medium">{{ t('developers.quickStart.issueTitle') }}</h3>
        <p class="text-sm text-muted-foreground">{{ t('developers.quickStart.issueDescription') }}</p>
        <CodeBlock :code="curl" />
        <p class="text-xs text-muted-foreground">{{ t('developers.quickStart.issueNote') }}</p>
      </div>

      <div class="grid gap-2">
        <div class="flex flex-wrap items-center justify-between gap-2">
          <h3 class="text-sm font-medium">{{ t('developers.quickStart.verifyTitle') }}</h3>
          <div class="flex gap-1" role="group" :aria-label="t('developers.quickStart.language')">
            <Button type="button" size="xs" :variant="language === 'node' ? 'secondary' : 'ghost'" :aria-pressed="language === 'node'" @click="language = 'node'">Node.js</Button>
            <Button type="button" size="xs" :variant="language === 'php' ? 'secondary' : 'ghost'" :aria-pressed="language === 'php'" @click="language = 'php'">PHP</Button>
          </div>
        </div>
        <p class="text-sm text-muted-foreground">{{ t('developers.quickStart.verifyDescription') }}</p>
        <CodeBlock :code="language === 'node' ? node : php" />
      </div>

      <div class="flex flex-wrap items-center gap-3">
        <Button as-child variant="outline" size="sm">
          <a href="/docs/api" target="_blank" rel="noopener noreferrer">
            <ExternalLinkIcon />
            {{ t('developers.quickStart.reference') }}
          </a>
        </Button>
        <span class="text-xs text-muted-foreground">{{ t('developers.quickStart.referenceNote') }}</span>
      </div>
    </div>
  </FormSection>
</template>
