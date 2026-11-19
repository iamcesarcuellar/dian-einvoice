import { createRouter, createWebHistory } from 'vue-router'
import type { Me } from '@/api/types'
import { product } from '@/config/product'
import { i18n } from '@/i18n'
import { meQuery, queryClient } from '@/lib/query'
import { useSessionStore } from '@/stores/session'

declare module 'vue-router' {
  interface RouteMeta {
    /** Pages for visitors without a session, such as sign in. */
    guest?: boolean
    /** Pages for everyone, with or without a session, such as the link of an invitation. */
    public?: boolean
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
      path: '/invitations/:token',
      name: 'invitation',
      component: () => import('@/pages/InvitationPage.vue'),
      meta: { public: true, title: 'invitation.title' },
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
          // Invoices, credit notes and debit notes: three tabs of one section.
          path: 'invoices',
          component: () => import('@/layouts/DocumentsLayout.vue'),
          children: [
            {
              path: '',
              name: 'invoices',
              component: () => import('@/pages/InvoicesPage.vue'),
              props: { kind: 'invoice' },
              meta: { title: 'invoices.title' },
            },
            {
              path: 'credit-notes',
              name: 'credit-notes',
              component: () => import('@/pages/InvoicesPage.vue'),
              props: { kind: 'credit_note' },
              meta: { title: 'notes.tabs.creditNotes' },
            },
            {
              path: 'debit-notes',
              name: 'debit-notes',
              component: () => import('@/pages/InvoicesPage.vue'),
              props: { kind: 'debit_note' },
              meta: { title: 'notes.tabs.debitNotes' },
            },
          ],
        },
        {
          path: 'invoices/new',
          name: 'invoice-new',
          component: () => import('@/pages/InvoicePage.vue'),
          props: { kind: 'invoice' },
          meta: { title: 'invoices.editor.newTitle' },
        },
        {
          path: 'invoices/credit-notes/new',
          name: 'credit-note-new',
          component: () => import('@/pages/InvoicePage.vue'),
          props: { kind: 'credit_note' },
          meta: { title: 'notes.editor.newTitle.credit_note' },
        },
        {
          path: 'invoices/credit-notes/:id',
          name: 'credit-note',
          component: () => import('@/pages/InvoicePage.vue'),
          props: { kind: 'credit_note' },
          meta: { title: 'notes.one.credit_note' },
        },
        {
          path: 'invoices/debit-notes/new',
          name: 'debit-note-new',
          component: () => import('@/pages/InvoicePage.vue'),
          props: { kind: 'debit_note' },
          meta: { title: 'notes.editor.newTitle.debit_note' },
        },
        {
          path: 'invoices/debit-notes/:id',
          name: 'debit-note',
          component: () => import('@/pages/InvoicePage.vue'),
          props: { kind: 'debit_note' },
          meta: { title: 'notes.one.debit_note' },
        },
        {
          path: 'invoices/:id',
          name: 'invoice',
          component: () => import('@/pages/InvoicePage.vue'),
          props: { kind: 'invoice' },
          meta: { title: 'invoices.invoice' },
        },
        {
          // Payments received and the receivables: two tabs of one section.
          path: 'payments',
          component: () => import('@/layouts/PaymentsLayout.vue'),
          children: [
            {
              path: '',
              name: 'payments',
              component: () => import('@/pages/PaymentsPage.vue'),
              meta: { title: 'payments.title' },
            },
            {
              path: 'receivables',
              name: 'receivables',
              component: () => import('@/pages/ReceivablesPage.vue'),
              meta: { title: 'payments.tabs.receivables' },
            },
          ],
        },
        {
          path: 'payments/new',
          name: 'payment-new',
          component: () => import('@/pages/PaymentPage.vue'),
          meta: { title: 'payments.editor.title' },
        },
        {
          path: 'payments/:id',
          name: 'payment',
          component: () => import('@/pages/PaymentPage.vue'),
          meta: { title: 'payments.payment' },
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
          path: 'profile',
          name: 'profile',
          component: () => import('@/pages/ProfilePage.vue'),
          meta: { title: 'profile.title' },
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
            {
              path: 'dian',
              name: 'dian-settings',
              component: () => import('@/pages/DianSettingsPage.vue'),
              meta: { title: 'settings.tabs.dian' },
            },
            {
              path: 'team',
              name: 'team',
              component: () => import('@/pages/TeamPage.vue'),
              meta: { title: 'settings.tabs.team' },
            },
            {
              path: 'developers',
              name: 'developers',
              component: () => import('@/pages/DevelopersPage.vue'),
              meta: { title: 'settings.tabs.developers' },
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

  if (to.meta.public) {
    return true
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
  document.title = to.meta.title ? `${i18n.global.t(to.meta.title)} · ${product.name}` : product.name
})
