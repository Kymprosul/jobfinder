<script setup>
defineProps({
  title: {
    type: String,
    required: true,
  },
  value: {
    type: [String, Number],
    required: true,
  },
  helper: {
    type: String,
    default: '',
  },
})
</script>

<template>
  <article class="stat-card">
    <p class="stat-card__title">{{ title }}</p>
    <strong class="stat-card__value">{{ value }}</strong>
    <p v-if="helper" class="stat-card__helper">{{ helper }}</p>
  </article>
</template>
