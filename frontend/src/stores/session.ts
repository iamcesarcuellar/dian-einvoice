import { defineStore } from 'pinia'
import { ref } from 'vue'
import type { Company } from '@/api/types'

const STORAGE_KEY = 'dian-einvoice.current-company'

function readStoredCompanyId(): string | null {
  try {
    return localStorage.getItem(STORAGE_KEY)
  } catch {
    return null
  }
}

function storeCompanyId(id: string | null): void {
  try {
    if (id) {
      localStorage.setItem(STORAGE_KEY, id)
    } else {
      localStorage.removeItem(STORAGE_KEY)
    }
  } catch {
    // Storage can be unavailable, as in private windows; the choice then lasts for this tab.
  }
}

/** The company the user is working in. The API client sends its id in the X-Company-Id header. */
export const useSessionStore = defineStore('session', () => {
  const currentCompanyId = ref<string | null>(readStoredCompanyId())

  function selectCompany(id: string | null): void {
    currentCompanyId.value = id
    storeCompanyId(id)
  }

  /** Keeps the choice valid: falls back to the first company when the current one is not in the list. */
  function syncCompanies(companies: Company[]): void {
    if (!companies.some((company) => company.id === currentCompanyId.value)) {
      selectCompany(companies[0]?.id ?? null)
    }
  }

  return { currentCompanyId, selectCompany, syncCompanies }
})
