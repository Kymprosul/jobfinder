import { reactive, watch } from 'vue'

export function useFilters(initialFilters = {}, onFiltersChange = null) {
  const filters = reactive({ ...initialFilters })

  function reset() {
    Object.keys(initialFilters).forEach((key) => {
      filters[key] = initialFilters[key]
    })
  }

  if (onFiltersChange) {
    watch(
      () => Object.values(filters),
      () => {
        onFiltersChange({ ...filters })
      },
    )
  }

  return {
    filters,
    reset,
  }
}
