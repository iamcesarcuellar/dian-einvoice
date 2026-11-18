import type { Component } from 'vue'

export interface DataTableColumn {
  key: string
  label: string
  /** Extra classes for the header and the cells of the column. */
  class?: string
  align?: 'left' | 'right'
}

export interface RowAction {
  label: string
  icon?: Component
  destructive?: boolean
  disabled?: boolean
  onSelect: () => void
}
