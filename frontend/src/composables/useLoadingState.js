import { shallowRef } from 'vue'

export function useLoadingState() {
  const loading = shallowRef(false)
  const error = shallowRef('')

  async function run(asyncFn, ...args) {
    loading.value = true
    error.value = ''
    try {
      return await asyncFn(...args)
    } catch (err) {
      error.value = err instanceof Error ? err.message : 'Error inesperado'
      throw err
    } finally {
      loading.value = false
    }
  }

  return {
    loading,
    error,
    run,
  }
}
