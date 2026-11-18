<script setup lang="ts">
import AppSidebar from '@/components/AppSidebar.vue'
import CompanySwitcher from '@/components/CompanySwitcher.vue'
import UserMenu from '@/components/UserMenu.vue'
</script>

<template>
  <div class="flex min-h-screen bg-muted/40">
    <AppSidebar />

    <div class="flex min-w-0 flex-1 flex-col">
      <header class="sticky top-0 z-10 flex h-14 shrink-0 items-center justify-between gap-4 border-b bg-background px-4 lg:px-6">
        <CompanySwitcher />
        <UserMenu />
      </header>

      <main class="flex-1 px-6 py-8 lg:px-10 lg:py-10">
        <div class="mx-auto w-full max-w-5xl">
          <RouterView />
        </div>
      </main>
    </div>
  </div>
</template>
