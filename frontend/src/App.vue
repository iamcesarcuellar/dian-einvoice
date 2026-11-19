<script setup lang="ts">
import ConfirmHost from '@/components/ConfirmHost.vue'
import { Toaster } from '@/components/ui/sonner'
import { TooltipProvider } from '@/components/ui/tooltip'
import { useTheme } from '@/composables/useTheme'

const { resolved } = useTheme()
</script>

<template>
  <TooltipProvider :delay-duration="200">
    <RouterView />
  </TooltipProvider>
  <ConfirmHost />
  <Toaster :theme="resolved" position="bottom-right" close-button />
</template>
