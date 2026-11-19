<script setup lang="ts">
import type { ListboxRootEmits, ListboxRootProps } from 'reka-ui'
import type { HTMLAttributes } from 'vue'
import { reactiveOmit } from '@vueuse/core'
import { cn } from 'cn'
import { ListboxRoot, useFilter, useForwardPropsEmits } from 'reka-ui'
import { reactive, ref, watch } from 'vue'
import { provideCommandContext } from '.'

const props = withDefaults(defineProps<ListboxRootProps & {
  class?: HTMLAttributes['class']
  /** False when the items are already the results of a search, such as those of the server. */
  shouldFilter?: boolean
}>(), {
  modelValue: '',
  highlightOnHover: true,
  shouldFilter: true,
})

const emits = defineEmits<ListboxRootEmits>()

const delegatedProps = reactiveOmit(props, 'class', 'shouldFilter')

/** The text typed in CommandInput. */
const search = defineModel<string>('search', { default: '' })

const forwarded = useForwardPropsEmits(delegatedProps, emits)

const allItems = ref<Map<string, string>>(new Map())
const allGroups = ref<Map<string, Set<string>>>(new Map())

const { contains } = useFilter({ sensitivity: 'base' })
const filterState = reactive({
  search: search.value,
  enabled: props.shouldFilter,
  filtered: {
    /** The count of all visible items. */
    count: 0,
    /** Map from visible item id to its search score. */
    items: new Map() as Map<string, number>,
    /** Set of groups with at least one visible item. */
    groups: new Set() as Set<string>,
  },
})

function filterItems() {
  if (!filterState.search || !filterState.enabled) {
    filterState.filtered.count = allItems.value.size
    // Do nothing, each item will know to show itself because search is empty
    return
  }

  // Reset the groups
  filterState.filtered.groups = new Set()
  let itemCount = 0

  // Check which items should be included
  for (const [id, value] of allItems.value) {
    const score = contains(value, filterState.search)
    filterState.filtered.items.set(id, score ? 1 : 0)
    if (score)
      itemCount++
  }

  // Check which groups have at least 1 item shown
  for (const [groupId, group] of allGroups.value) {
    for (const itemId of group) {
      if (filterState.filtered.items.get(itemId)! > 0) {
        filterState.filtered.groups.add(groupId)
        break
      }
    }
  }

  filterState.filtered.count = itemCount
}

watch(() => filterState.search, (value) => {
  search.value = value
  filterItems()
})

watch(search, (value) => {
  if (value !== filterState.search) {
    filterState.search = value
  }
})

watch(() => props.shouldFilter, (value) => {
  filterState.enabled = value
  filterItems()
})

provideCommandContext({
  allItems,
  allGroups,
  filterState,
})
</script>

<template>
  <ListboxRoot
    data-slot="command"
    v-bind="forwarded"
    :class="cn('bg-popover text-popover-foreground rounded-xl! p-1 flex size-full flex-col overflow-hidden', props.class)"
  >
    <slot />
  </ListboxRoot>
</template>
