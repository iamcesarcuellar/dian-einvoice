import { createRouter, createWebHistory } from 'vue-router'
import type { Me } from '@/api/types'
import { i18n } from '@/i18n'
import { meQuery, queryClient } from '@/lib/query'
import { useSessionStore } from '@/stores/session'

declare module 'vue-router' {
  interface RouteMeta {
    /** Pages for visitors without a session, such as sign in. */
    guest?: boolean
    /** i18n key of the page title. */
    title?: string
  }
}

export const router = createRouter({
  history: createWebHistory(),
  routes: [
    {
      path: '/login',
      name: 'login',
      component: () => import('@/pages/LoginPage.vue'),
      meta: { guest: true, title: 'auth.login.title' },
    },
    {
      path: '/register',
      name: 'register',
      component: () => import('@/pages/RegisterPage.vue'),
      meta: { guest: true, title: 'auth.register.title' },
    },
    {
      path: '/',
      component: () => import('@/layouts/AppLayout.vue'),
      children: [
        {
          path: '',
          name: 'dashboard',
          component: () => import('@/pages/DashboardPage.vue'),
          meta: { title: 'nav.dashboard' },
        },
        {
          path: 'contacts',
          name: 'contacts',
          component: () => import('@/pages/ContactsPage.vue'),
          meta: { title: 'contacts.title' },
        },
        {
          path: 'contacts/new',
          name: 'contact-new',
          component: () => import('@/pages/ContactFormPage.vue'),
          meta: { title: 'contacts.newTitle' },
        },
        {
          path: 'contacts/:id',
          name: 'contact-edit',
          component: () => import('@/pages/ContactFormPage.vue'),
          meta: { title: 'contacts.editTitle' },
        },
        {
          path: 'items',
          name: 'items',
          component: () => import('@/pages/ItemsPage.vue'),
          meta: { title: 'items.title' },
        },
        {
          path: 'settings',
          component: () => import('@/layouts/SettingsLayout.vue'),
          redirect: { name: 'company-settings' },
          children: [
            {
              path: 'company',
              name: 'company-settings',
              component: () => import('@/pages/CompanySettingsPage.vue'),
              meta: { title: 'settings.tabs.company' },
            },
            {
              path: 'taxes',
              name: 'taxes',
              component: () => import('@/pages/RatesPage.vue'),
              props: { kind: 'taxes' },
              meta: { title: 'settings.tabs.taxes' },
            },
            {
              path: 'withholdings',
              name: 'withholdings',
              component: () => import('@/pages/RatesPage.vue'),
              props: { kind: 'withholdings' },
              meta: { title: 'settings.tabs.withholdings' },
            },
            {
              path: 'numbering',
              name: 'numbering',
              component: () => import('@/pages/NumberingPage.vue'),
              meta: { title: 'settings.tabs.numbering' },
            },
          ],
        },
      ],
    },
    { path: '/:pathMatch(.*)*', redirect: '/' },
  ],
})

async function loadSession(): Promise<Me | null> {
  try {
    return await queryClient.ensureQueryData(meQuery)
  } catch {
    // The API is unreachable: treat the visitor as signed out; the sign-in form reports errors.
    return null
  }
}

// Private pages need a session; guest pages send signed-in users to the dashboard.
router.beforeEach(async (to) => {
  const me = await loadSession()

  if (me) {
    useSessionStore().syncCompanies(me.companies)
  }

  if (to.meta.guest) {
    return me ? { name: 'dashboard' } : true
  }

  if (!me) {
    return { name: 'login', query: to.fullPath === '/' ? {} : { redirect: to.fullPath } }
  }

  return true
})

router.afterEach((to) => {
  const appName = i18n.global.t('app.name')

  document.title = to.meta.title ? `${i18n.global.t(to.meta.title)} · ${appName}` : appName
})
