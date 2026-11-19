<script setup lang="ts">
import type { Role } from '@/api/types'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import SelectInput from '@/components/SelectInput.vue'

/** A role picker that explains below what the chosen role allows. */
const props = defineProps<{
  id: string
  roles: Role[]
  invalid?: boolean
  disabled?: boolean
}>()

const model = defineModel<Role>({ required: true })

const { t } = useI18n()

const options = computed(() => props.roles.map((role) => ({ value: role, label: t(`companies.roles.${role}`) })))

const value = computed<string | null>({
  get: () => model.value,
  set: (role) => {
    if (role && (props.roles as string[]).includes(role)) {
      model.value = role as Role
    }
  },
})
</script>

<template>
  <div class="grid gap-1.5">
    <SelectInput :id="id" v-model="value" :options="options" :invalid="invalid" :disabled="disabled" />
    <p class="text-xs text-muted-foreground">{{ t(`team.roleHints.${model}`) }}</p>
  </div>
</template>
