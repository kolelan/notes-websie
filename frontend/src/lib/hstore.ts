function unescapeHstoreValue(value: string): string {
  return value.replace(/\\\\/g, '\\').replace(/\\"/g, '"')
}

function stripWrappingQuotes(value: string): string {
  const trimmed = value.trim()
  if (trimmed.length >= 2 && trimmed.startsWith('"') && trimmed.endsWith('"')) {
    return trimmed.slice(1, -1)
  }
  return trimmed
}

function parseHstoreLiteral(source: string): Record<string, string> {
  const result: Record<string, string> = {}
  const pattern = /"((?:[^"\\]|\\.)*)"\s*=>\s*"((?:[^"\\]|\\.)*)"/g
  let match: RegExpExecArray | null = pattern.exec(source)
  while (match) {
    const key = unescapeHstoreValue(match[1]).trim()
    const value = unescapeHstoreValue(match[2]).trim()
    if (key) result[key] = value
    match = pattern.exec(source)
  }
  return result
}

export function parseHstoreInput(source: string): Record<string, string> {
  const result: Record<string, string> = {}
  const literalParsed = parseHstoreLiteral(source)
  if (Object.keys(literalParsed).length > 0) return literalParsed

  for (const rawLine of source.split('\n')) {
    const line = rawLine.trim()
    if (!line) continue
    const idx = line.indexOf('=')
    if (idx <= 0) continue
    const key = stripWrappingQuotes(line.slice(0, idx))
    const value = stripWrappingQuotes(line.slice(idx + 1))
    if (key) result[key] = value
  }
  return result
}

export function formatHstoreOutput(value: unknown): string {
  if (!value) return ''
  if (typeof value === 'object' && !Array.isArray(value)) {
    return Object.entries(value as Record<string, string>)
      .map(([key, val]) => `${key}=${val}`)
      .join('\n')
  }
  if (typeof value === 'string') {
    const parsed = parseHstoreLiteral(value)
    if (Object.keys(parsed).length > 0) {
      return Object.entries(parsed)
        .map(([key, val]) => `${key}=${val}`)
        .join('\n')
    }
    return value
  }
  return ''
}

export function validateHstoreInput(source: string): string[] {
  const errors: string[] = []
  const literalParsed = parseHstoreLiteral(source)
  if (Object.keys(literalParsed).length > 0) return errors

  source.split('\n').forEach((rawLine, index) => {
    const line = rawLine.trim()
    if (!line) return
    if (!line.includes('=')) {
      errors.push(`Строка ${index + 1}: ожидается формат key=value или "key"=>"value".`)
      return
    }
    const idx = line.indexOf('=')
    const key = stripWrappingQuotes(line.slice(0, idx))
    if (!key.trim()) {
      errors.push(`Строка ${index + 1}: ключ не может быть пустым.`)
    }
  })
  return errors
}
