function stringHash(value) {
  let hash = 0

  for (const char of String(value || '')) {
    hash = (hash * 31 + char.charCodeAt(0)) % 360
  }

  return hash
}

export function sourceColorStyle(source) {
  const hue = stringHash(source)

  return {
    '--source-bg': `hsl(${hue} 42% 92%)`,
    '--source-border': `hsl(${hue} 32% 78%)`,
    '--source-color': `hsl(${hue} 36% 30%)`,
  }
}
