import type { Component } from 'vue'

export interface DataTableColumn {
  key: string
  label: string
  /** Extra classes for the header and the cells of the column. */
  class?: string
  align?: 'left' | 'right'
  /**
   * The column gives way first when the table is wider than its box, as a customer name does: its
   * cells may shrink below their text, which is cut with an ellipsis (its slot content should be a
   * block with the truncate class), so no other column is pushed out of view.
   */
  truncate?: boolean
}

export interface RowAction {
  label: string
  icon?: Component
  destructive?: boolean
  disabled?: boolean
  onSelect: () => void
}
