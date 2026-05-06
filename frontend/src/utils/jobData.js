export function matchesJobText(job, searchText) {
  const normalizedText = String(searchText || '').trim().toLowerCase()

  if (normalizedText === '') {
    return true
  }

  return [job?.title, job?.institution, job?.location]
    .join(' ')
    .toLowerCase()
    .includes(normalizedText)
}

export function matchesSearchScope(job, searchKey) {
  const normalizedSearchKey = String(searchKey || '').trim()

  if (normalizedSearchKey === '') {
    return true
  }

  if (String(job?.category || '').trim() !== normalizedSearchKey) {
    return false
  }

  const rawSearchCategory = String(job?.raw_meta?.search_category || '').trim()

  if (rawSearchCategory !== '' && rawSearchCategory !== normalizedSearchKey) {
    return false
  }

  return true
}

export function publisherLabel(job, fallbackLabel = 'Publicador no indicado') {
  const institution = String(job?.institution || '').trim()

  return institution || fallbackLabel
}

export function publisherKey(job, fallbackLabel = 'Publicador no indicado') {
  return publisherLabel(job, fallbackLabel).toLocaleLowerCase('en-US')
}

export function jobSources(job) {
  const sources = new Set()

  for (const source of job?.raw_meta?.seen_sources || []) {
    const normalized = String(source || '').trim()
    if (normalized !== '') {
      sources.add(normalized)
    }
  }

  const currentSource = String(job?.source || '').trim()
  if (currentSource !== '') {
    sources.add(currentSource)
  }

  return [...sources].sort()
}
