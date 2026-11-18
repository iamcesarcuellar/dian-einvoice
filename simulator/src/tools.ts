// The schema and signature checks run the libxml2 and xmlsec1 command line tools, on copies in a
// temporary directory, a few at a time so that a burst of requests does not exhaust the memory.
import { execFile } from 'node:child_process'
import { mkdtemp, rm, writeFile } from 'node:fs/promises'
import { tmpdir } from 'node:os'
import { join } from 'node:path'

interface Run {
  code: number
  output: string
}

class Limiter {
  private running = 0
  private readonly waiting: Array<() => void> = []
  private readonly max: number

  constructor(max: number) {
    this.max = max
  }

  async run<T>(task: () => Promise<T>): Promise<T> {
    if (this.running >= this.max) {
      await new Promise<void>((resolve) => this.waiting.push(resolve))
    }

    this.running++

    try {
      return await task()
    } finally {
      this.running--
      this.waiting.shift()?.()
    }
  }
}

const limiter = new Limiter(Number(process.env.SIMULATOR_CHECKS ?? 4))

function run(command: string, args: string[]): Promise<Run> {
  return new Promise((resolve) => {
    execFile(command, args, { timeout: 30_000, maxBuffer: 4 * 1024 * 1024 }, (error, stdout, stderr) => {
      const code = error === null ? 0 : (typeof error.code === 'number' ? error.code : 1)

      resolve({ code, output: `${String(stdout)}\n${String(stderr)}`.trim() })
    })
  })
}

async function inTemporaryDirectory<T>(task: (directory: string) => Promise<T>): Promise<T> {
  const directory = await mkdtemp(join(tmpdir(), 'dian-simulator-'))

  try {
    return await task(directory)
  } finally {
    await rm(directory, { recursive: true, force: true })
  }
}

/**
 * Errors of the document against the official XSD; empty when it is valid. xsi:schemaLocation is
 * removed from the copy and --nonet is set, so libxml2 only uses the local schemas.
 */
export function schemaErrors(xml: string, schema: string): Promise<string[]> {
  return limiter.run(() => inTemporaryDirectory(async (directory) => {
    const file = join(directory, 'document.xml')
    await writeFile(file, xml.replace(/\sxsi:schemaLocation="[^"]*"/, ''), 'utf8')

    const result = await run('xmllint', ['--noout', '--nonet', '--schema', schema, file])

    if (result.code === 0) {
      return []
    }

    const errors = result.output
      .split('\n')
      .map((line) => line.trim())
      .filter((line) => line !== '' && !line.endsWith('validates') && !line.endsWith('fails to validate'))
      .map((line) => line.replace(`${file}:`, 'line '))

    return errors.length > 0 ? errors : [`xmllint exited with ${result.code}`]
  }))
}

/**
 * Whether xmlsec1 verifies the XML signature (references and signature value) with the key of the
 * certificate it carries. --insecure skips the certificate chain: the validity and the issuer of
 * the certificate are checked apart.
 */
export function signatureVerifies(xml: string, certificatePem: string): Promise<{ ok: boolean, detail: string }> {
  return limiter.run(() => inTemporaryDirectory(async (directory) => {
    const file = join(directory, 'signed.xml')
    const certificate = join(directory, 'certificate.pem')
    await writeFile(file, xml, 'utf8')
    await writeFile(certificate, certificatePem, 'utf8')

    const result = await run('xmlsec1', ['--verify', '--insecure', '--trusted-pem', certificate, file])
    const detail = result.output.split('\n').map((line) => line.trim()).filter(Boolean).slice(-2).join(' ')

    return { ok: result.code === 0, detail: detail.replaceAll(file, 'document') }
  }))
}
