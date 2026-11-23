// The web app at the edge: the same script for every stage (make cloud-up locally; the delivery pipeline of prod).
//
//   node scripts/panel.mjs upload <directory>   uploads the build of the web app (vite build) to the panel bucket of
//                                               the stage, each file with its type and its cache: a year, immutable,
//                                               for the files whose names carry the hash of their content (assets/),
//                                               no-cache for index.html and every other one. The fingerprinted files
//                                               go first and index.html last, so no page names a file not uploaded
//                                               yet. Files of earlier builds not in this one are removed, except the
//                                               fingerprinted ones of the last week, which a tab still open may load.
//                                               In prod the distribution then drops the files that are not
//                                               fingerprinted (an invalidation); the emulator caches nothing.
//   node scripts/panel.mjs rollback             puts back the previous version of every file that is not
//                                               fingerprinted (the bucket is versioned), index.html included: the
//                                               earlier build, whose fingerprinted files are still there. In prod,
//                                               the same invalidation.
//
// The bucket and the distribution come from the parameters of the stack edge (/dian-einvoice/<stage>/edge/...).
import { readdirSync, readFileSync, statSync } from 'node:fs'
import { join, relative, sep } from 'node:path'
import { CloudFrontClient, CreateInvalidationCommand } from '@aws-sdk/client-cloudfront'
import { CopyObjectCommand, DeleteObjectsCommand, ListObjectsV2Command, ListObjectVersionsCommand, PutObjectCommand, S3Client } from '@aws-sdk/client-s3'
import { GetParametersCommand, SSMClient } from '@aws-sdk/client-ssm'

const STAGE = process.env.STAGE ?? 'local'
const FINGERPRINTED = /^assets\/.+-[A-Za-z0-9_-]{8}\.[a-z0-9]+$/
const IMMUTABLE = 'public, max-age=31536000, immutable'
const REVALIDATE = 'no-cache'
// Fingerprinted files of earlier builds are kept this long for the tabs still open on them.
const KEEP_EARLIER_ASSETS_MS = 7 * 24 * 3600 * 1000

const TYPES = {
  html: 'text/html; charset=utf-8',
  js: 'text/javascript; charset=utf-8',
  mjs: 'text/javascript; charset=utf-8',
  css: 'text/css; charset=utf-8',
  json: 'application/json',
  map: 'application/json',
  txt: 'text/plain; charset=utf-8',
  svg: 'image/svg+xml',
  png: 'image/png',
  jpg: 'image/jpeg',
  webp: 'image/webp',
  ico: 'image/x-icon',
  woff2: 'font/woff2',
  woff: 'font/woff',
  webmanifest: 'application/manifest+json',
}

async function parameters() {
  const names = ['panel-bucket-name', 'distribution-id'].map((name) => `/dian-einvoice/${STAGE}/edge/${name}`)
  const { Parameters: found = [] } = await new SSMClient({}).send(new GetParametersCommand({ Names: names }))
  const value = (name) => found.find((parameter) => parameter.Name === `/dian-einvoice/${STAGE}/edge/${name}`)?.Value

  if (value('panel-bucket-name') === undefined || value('distribution-id') === undefined) {
    throw new Error(`The edge stack of the ${STAGE} stage publishes no bucket or distribution: deploy it first.`)
  }

  return { bucket: value('panel-bucket-name'), distribution: value('distribution-id') }
}

function files(directory) {
  return readdirSync(directory, { recursive: true })
    .map((entry) => join(directory, String(entry)))
    .filter((path) => statSync(path).isFile())
    .map((path) => relative(directory, path).split(sep).join('/'))
}

function headersOf(key) {
  const extension = key.slice(key.lastIndexOf('.') + 1).toLowerCase()
  const type = TYPES[extension]

  if (type === undefined) {
    throw new Error(`${key}: no type is known for .${extension}; add it to scripts/panel.mjs rather than serve it untyped.`)
  }

  if (key.startsWith('assets/') && !FINGERPRINTED.test(key)) {
    throw new Error(`${key} is under assets/ without the hash of its content in its name: it would be cached a year.`)
  }

  return { ContentType: type, CacheControl: FINGERPRINTED.test(key) ? IMMUTABLE : REVALIDATE }
}

async function invalidate(distribution, keys) {
  if (STAGE === 'local') {
    process.stdout.write('The emulator caches nothing: no invalidation in the local stage.\n')

    return
  }

  const paths = [...new Set(['/', ...keys.map((key) => `/${key}`)])]
  await new CloudFrontClient({}).send(new CreateInvalidationCommand({
    DistributionId: distribution,
    InvalidationBatch: { CallerReference: `panel-${Date.now()}`, Paths: { Quantity: paths.length, Items: paths } },
  }))
  process.stdout.write(`Invalidated ${paths.length} paths of the distribution ${distribution}: ${paths.join(' ')}.\n`)
}

async function upload(directory) {
  const { bucket, distribution } = await parameters()
  const keys = files(directory)

  if (!keys.includes('index.html')) {
    throw new Error(`${directory} holds no index.html: build the web app first.`)
  }

  // Every type and cache is decided before anything is uploaded.
  const planned = keys.map((key) => [key, headersOf(key)])
  const order = [...planned.filter(([key]) => FINGERPRINTED.test(key)), ...planned.filter(([key]) => !FINGERPRINTED.test(key) && key !== 'index.html'), ...planned.filter(([key]) => key === 'index.html')]
  const s3 = new S3Client({ forcePathStyle: process.env.AWS_ENDPOINT_URL !== undefined })

  for (const [key, headers] of order) {
    await s3.send(new PutObjectCommand({ Bucket: bucket, Key: key, Body: readFileSync(join(directory, key)), ...headers }))
  }

  // Files of earlier builds: gone at once unless fingerprinted, which stay a week.
  const stale = []
  let token

  do {
    const page = await s3.send(new ListObjectsV2Command({ Bucket: bucket, ContinuationToken: token }))

    for (const object of page.Contents ?? []) {
      const old = Date.now() - (object.LastModified?.getTime() ?? 0) > KEEP_EARLIER_ASSETS_MS

      if (!keys.includes(object.Key) && (!FINGERPRINTED.test(object.Key) || old)) {
        stale.push({ Key: object.Key })
      }
    }

    token = page.IsTruncated ? page.NextContinuationToken : undefined
  } while (token !== undefined)

  for (let start = 0; start < stale.length; start += 1000) {
    await s3.send(new DeleteObjectsCommand({ Bucket: bucket, Delete: { Objects: stale.slice(start, start + 1000) } }))
  }

  const fingerprinted = keys.filter((key) => FINGERPRINTED.test(key)).length
  process.stdout.write(`Uploaded the web app to ${bucket}: ${keys.length} files, ${fingerprinted} fingerprinted (${IMMUTABLE}), ${keys.length - fingerprinted} more (${REVALIDATE}); ${stale.length} files of earlier builds removed.\n`)
  await invalidate(distribution, keys.filter((key) => !FINGERPRINTED.test(key)))
}

async function rollback() {
  const { bucket, distribution } = await parameters()
  const s3 = new S3Client({ forcePathStyle: process.env.AWS_ENDPOINT_URL !== undefined })
  const versions = []
  let keyMarker
  let versionMarker

  do {
    const page = await s3.send(new ListObjectVersionsCommand({ Bucket: bucket, KeyMarker: keyMarker, VersionIdMarker: versionMarker }))
    versions.push(...(page.Versions ?? []))
    keyMarker = page.IsTruncated ? page.NextKeyMarker : undefined
    versionMarker = page.IsTruncated ? page.NextVersionIdMarker : undefined
  } while (keyMarker !== undefined)

  const restored = []

  for (const key of [...new Set(versions.map((version) => version.Key))].filter((candidate) => !FINGERPRINTED.test(candidate))) {
    const history = versions.filter((version) => version.Key === key).sort((a, b) => (b.LastModified?.getTime() ?? 0) - (a.LastModified?.getTime() ?? 0))
    const previous = history.find((version) => !version.IsLatest)

    if (previous !== undefined) {
      await s3.send(new CopyObjectCommand({ Bucket: bucket, Key: key, CopySource: `${bucket}/${encodeURIComponent(key)}?versionId=${encodeURIComponent(previous.VersionId)}` }))
      restored.push(key)
    }
  }

  if (!restored.includes('index.html')) {
    throw new Error(`${bucket} keeps no earlier version of index.html to go back to.`)
  }

  process.stdout.write(`Put back the earlier version of ${restored.length} files of ${bucket}: ${restored.join(', ')}.\n`)
  await invalidate(distribution, restored)
}

const [action, directory] = process.argv.slice(2)

if (action === 'upload' && directory !== undefined) {
  await upload(directory)
} else if (action === 'rollback') {
  await rollback()
} else {
  process.stderr.write('usage: node scripts/panel.mjs upload <directory>|rollback\n')
  process.exit(2)
}
