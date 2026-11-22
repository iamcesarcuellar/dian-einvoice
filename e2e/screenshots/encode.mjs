// make screenshots, after screens.spec.ts: the files of docs/assets from the captures of the browser, with nothing but
// Node.js itself (zlib). Each screenshot is written as a lossless PNG and, only when that is over its limit, as the
// first of these that fits: its channels rounded to 6 bits, then to 5 bits (a step no eye tells on a screen), then a
// palette of 256 colours (median cut refined by k-means) with error diffusion. Each GIF from its frames, with one
// palette, the frames that change nothing merged into the one before, and each later frame only the rectangle that
// changed, its unchanged pixels transparent. The run fails when a screenshot is over 400 KB or a GIF over 5 MB.
//
//   node screenshots/encode.mjs <directory of the captures> <directory of the assets>
import { readdirSync, readFileSync, writeFileSync } from 'node:fs'
import { basename, join } from 'node:path'
import { crc32, deflateSync, inflateSync } from 'node:zlib'

const [raw = '/tmp/screenshots', assets = '/assets'] = process.argv.slice(2)
const STILL_LIMIT = 400 * 1024
const GIF_LIMIT = 5 * 1024 * 1024
const PNG_SIGNATURE = Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a])

/** The pixels of a PNG of the browser (8 bits, RGB or RGBA, not interlaced), as RGB. */
function decodePng(buffer) {
  if (!buffer.subarray(0, 8).equals(PNG_SIGNATURE)) {
    throw new Error('not a PNG')
  }

  let width = 0
  let height = 0
  let channels = 0
  const data = []

  for (let offset = 8; offset < buffer.length; ) {
    const length = buffer.readUInt32BE(offset)
    const type = buffer.toString('latin1', offset + 4, offset + 8)
    const body = buffer.subarray(offset + 8, offset + 8 + length)

    if (type === 'IHDR') {
      width = body.readUInt32BE(0)
      height = body.readUInt32BE(4)

      if (body[8] !== 8 || body[12] !== 0 || (body[9] !== 2 && body[9] !== 6)) {
        throw new Error(`a PNG of bit depth ${body[8]}, colour type ${body[9]}, interlace ${body[12]}`)
      }

      channels = body[9] === 6 ? 4 : 3
    } else if (type === 'IDAT') {
      data.push(body)
    } else if (type === 'IEND') {
      break
    }

    offset += 12 + length
  }

  const stride = width * channels
  const inflated = inflateSync(Buffer.concat(data))
  const pixels = Buffer.alloc(width * height * 3)
  let previous = Buffer.alloc(stride)
  let current = Buffer.alloc(stride)

  for (let y = 0; y < height; y++) {
    const start = y * (stride + 1)
    const filter = inflated[start]

    for (let x = 0; x < stride; x++) {
      const a = x >= channels ? current[x - channels] : 0
      const b = previous[x]
      const c = x >= channels ? previous[x - channels] : 0
      let value = inflated[start + 1 + x]

      if (filter === 1) {
        value += a
      } else if (filter === 2) {
        value += b
      } else if (filter === 3) {
        value += (a + b) >> 1
      } else if (filter === 4) {
        const p = a + b - c
        const pa = Math.abs(p - a)
        const pb = Math.abs(p - b)
        const pc = Math.abs(p - c)
        value += pa <= pb && pa <= pc ? a : pb <= pc ? b : c
      } else if (filter !== 0) {
        throw new Error(`PNG filter ${filter}`)
      }

      current[x] = value & 0xff
    }

    for (let x = 0; x < width; x++) {
      current.copy(pixels, (y * width + x) * 3, x * channels, x * channels + 3)
    }

    ;[previous, current] = [current, previous]
  }

  return { width, height, pixels }
}

function chunk(type, body) {
  const head = Buffer.alloc(8)
  head.writeUInt32BE(body.length, 0)
  head.write(type, 4, 'latin1')
  const crc = Buffer.alloc(4)
  crc.writeUInt32BE(crc32(body, crc32(Buffer.from(type, 'latin1'))))

  return Buffer.concat([head, body, crc])
}

function png(width, height, colourType, scanlines, palette) {
  const header = Buffer.alloc(13)
  header.writeUInt32BE(width, 0)
  header.writeUInt32BE(height, 4)
  header[8] = 8
  header[9] = colourType

  return Buffer.concat([
    PNG_SIGNATURE,
    chunk('IHDR', header),
    ...(palette ? [chunk('PLTE', palette)] : []),
    chunk('IDAT', deflateSync(scanlines, { level: 9, memLevel: 9 })),
    chunk('IEND', Buffer.alloc(0)),
  ])
}

/** A lossless PNG, each row with the filter of the smallest sum of absolute values. */
function truecolourPng({ width, height, pixels }) {
  const stride = width * 3
  const scanlines = Buffer.alloc((stride + 1) * height)
  const candidate = Buffer.alloc(stride)
  const best = Buffer.alloc(stride)

  for (let y = 0; y < height; y++) {
    const row = pixels.subarray(y * stride, (y + 1) * stride)
    const up = y > 0 ? pixels.subarray((y - 1) * stride, y * stride) : null
    let bestFilter = 0
    let bestScore = Infinity

    for (let filter = 0; filter <= 4; filter++) {
      let score = 0

      for (let x = 0; x < stride; x++) {
        const a = x >= 3 ? row[x - 3] : 0
        const b = up ? up[x] : 0
        const c = up && x >= 3 ? up[x - 3] : 0
        let predictor = 0

        if (filter === 1) {
          predictor = a
        } else if (filter === 2) {
          predictor = b
        } else if (filter === 3) {
          predictor = (a + b) >> 1
        } else if (filter === 4) {
          const p = a + b - c
          const pa = Math.abs(p - a)
          const pb = Math.abs(p - b)
          const pc = Math.abs(p - c)
          predictor = pa <= pb && pa <= pc ? a : pb <= pc ? b : c
        }

        const value = (row[x] - predictor) & 0xff
        candidate[x] = value
        score += value < 128 ? value : 256 - value
      }

      if (score < bestScore) {
        bestScore = score
        bestFilter = filter
        candidate.copy(best)
      }
    }

    scanlines[y * (stride + 1)] = bestFilter
    best.copy(scanlines, y * (stride + 1) + 1)
  }

  return png(width, height, 2, scanlines)
}

/** The colours of some pixels with how often each appears: key 0xRRGGBB, value its count. */
function histogram(pixels, counts = new Map(), step = 1) {
  for (let index = 0; index < pixels.length; index += 3 * step) {
    const key = (pixels[index] << 16) | (pixels[index + 1] << 8) | pixels[index + 2]
    counts.set(key, (counts.get(key) ?? 0) + 1)
  }

  return counts
}

function distance(palette, entry, r, g, b) {
  const dr = palette[entry * 3] - r
  const dg = palette[entry * 3 + 1] - g
  const db = palette[entry * 3 + 2] - b

  // Weighted as the eye weighs red, green and blue.
  return 2 * dr * dr + 4 * dg * dg + 3 * db * db
}

function nearest(palette, size, key) {
  const r = key >> 16
  const g = (key >> 8) & 0xff
  const b = key & 0xff
  let best = 0
  let bestDistance = Infinity

  for (let entry = 0; entry < size; entry++) {
    const value = distance(palette, entry, r, g, b)

    if (value < bestDistance) {
      bestDistance = value
      best = entry
    }
  }

  return best
}

/** At most `size` colours for the histogram: median cut, then a few rounds of k-means weighted by the counts. */
function quantize(counts, size) {
  const colours = [...counts.entries()].map(([key, count]) => ({ r: key >> 16, g: (key >> 8) & 0xff, b: key & 0xff, count }))

  if (colours.length <= size) {
    return Buffer.from(colours.flatMap(({ r, g, b }) => [r, g, b]))
  }

  const measure = (items) => {
    const box = { items, count: 0, range: 0, channel: 'r' }
    const low = { r: 255, g: 255, b: 255 }
    const high = { r: 0, g: 0, b: 0 }

    for (const item of items) {
      box.count += item.count

      for (const channel of ['r', 'g', 'b']) {
        low[channel] = Math.min(low[channel], item[channel])
        high[channel] = Math.max(high[channel], item[channel])
      }
    }

    for (const channel of ['r', 'g', 'b']) {
      if (high[channel] - low[channel] > box.range) {
        box.range = high[channel] - low[channel]
        box.channel = channel
      }
    }

    // A box of many pixels and a box of a distinct colour both get split before a box of near shades.
    box.priority = box.items.length > 1 ? box.range * Math.log2(2 + box.count) : -1

    return box
  }

  const boxes = [measure(colours)]

  while (boxes.length < size) {
    boxes.sort((a, b) => b.priority - a.priority)
    const box = boxes[0]

    if (box.priority < 0) {
      break
    }

    box.items.sort((a, b) => a[box.channel] - b[box.channel])
    let seen = 0
    let split = 1

    for (let index = 0; index < box.items.length - 1; index++) {
      seen += box.items[index].count

      if (seen >= box.count / 2) {
        split = index + 1
        break
      }
    }

    boxes.splice(0, 1, measure(box.items.slice(0, split)), measure(box.items.slice(split)))
  }

  const palette = Buffer.alloc(boxes.length * 3)
  boxes.forEach((box, entry) => {
    const sum = box.items.reduce((total, item) => ({ r: total.r + item.r * item.count, g: total.g + item.g * item.count, b: total.b + item.b * item.count }), { r: 0, g: 0, b: 0 })
    palette[entry * 3] = Math.round(sum.r / box.count)
    palette[entry * 3 + 1] = Math.round(sum.g / box.count)
    palette[entry * 3 + 2] = Math.round(sum.b / box.count)
  })

  for (let round = 0; round < 3; round++) {
    const sums = new Float64Array(boxes.length * 4)

    for (const { r, g, b, count } of colours) {
      const entry = nearest(palette, boxes.length, (r << 16) | (g << 8) | b)
      sums[entry * 4] += r * count
      sums[entry * 4 + 1] += g * count
      sums[entry * 4 + 2] += b * count
      sums[entry * 4 + 3] += count
    }

    for (let entry = 0; entry < boxes.length; entry++) {
      if (sums[entry * 4 + 3] > 0) {
        palette[entry * 3] = Math.round(sums[entry * 4] / sums[entry * 4 + 3])
        palette[entry * 3 + 1] = Math.round(sums[entry * 4 + 1] / sums[entry * 4 + 3])
        palette[entry * 3 + 2] = Math.round(sums[entry * 4 + 2] / sums[entry * 4 + 3])
      }
    }
  }

  return palette
}

/** The palette index of every pixel, through a cache of the nearest entry of each colour. */
function indices(pixels, palette, cache) {
  const size = palette.length / 3
  const result = new Uint8Array(pixels.length / 3)

  for (let index = 0; index < result.length; index++) {
    const key = (pixels[index * 3] << 16) | (pixels[index * 3 + 1] << 8) | pixels[index * 3 + 2]
    let entry = cache.get(key)

    if (entry === undefined) {
      entry = nearest(palette, size, key)
      cache.set(key, entry)
    }

    result[index] = entry
  }

  return result
}

/** The image with each channel rounded to `bits` bits, so that fewer colours compress better. */
function rounded(image, bits) {
  const step = 1 << (8 - bits)
  const table = Uint8Array.from({ length: 256 }, (_, value) => Math.min(255, Math.round(value / step) * step))

  return { ...image, pixels: Buffer.from(image.pixels.map((value) => table[value])) }
}

/**
 * The palette index of every pixel with Floyd-Steinberg error diffusion, so that gradients (a blurred backdrop, a
 * shadow) keep their shades instead of bands.
 */
function diffused(image, palette) {
  const { width, height } = image
  const size = palette.length / 3
  const cache = new Map()
  const errors = new Float32Array(width * height * 3)
  const result = new Uint8Array(width * height)

  for (let y = 0; y < height; y++) {
    for (let x = 0; x < width; x++) {
      const at = y * width + x
      const channels = [0, 1, 2].map((channel) => Math.max(0, Math.min(255, Math.round(image.pixels[at * 3 + channel] + errors[at * 3 + channel]))))
      const key = (channels[0] << 16) | (channels[1] << 8) | channels[2]
      let entry = cache.get(key)

      if (entry === undefined) {
        entry = nearest(palette, size, key)
        cache.set(key, entry)
      }

      result[at] = entry

      for (let channel = 0; channel < 3; channel++) {
        const error = channels[channel] - palette[entry * 3 + channel]

        if (x + 1 < width) errors[(at + 1) * 3 + channel] += (error * 7) / 16
        if (y + 1 < height) {
          if (x > 0) errors[(at + width - 1) * 3 + channel] += (error * 3) / 16
          errors[(at + width) * 3 + channel] += (error * 5) / 16
          if (x + 1 < width) errors[(at + width + 1) * 3 + channel] += error / 16
        }
      }
    }
  }

  return result
}

/** A PNG of at most 256 colours, diffused; of the filters none, sub and up for every row, the smallest file. */
function palettePng(image) {
  const palette = quantize(histogram(image.pixels), 256)
  const mapped = diffused(image, palette)
  const { width, height } = image
  let smallest = null

  for (const filter of [0, 1, 2]) {
    const scanlines = Buffer.alloc((width + 1) * height)

    for (let y = 0; y < height; y++) {
      scanlines[y * (width + 1)] = filter

      for (let x = 0; x < width; x++) {
        const value = mapped[y * width + x]
        const predictor = filter === 1 ? (x > 0 ? mapped[y * width + x - 1] : 0) : filter === 2 ? (y > 0 ? mapped[(y - 1) * width + x] : 0) : 0
        scanlines[y * (width + 1) + 1 + x] = (value - predictor) & 0xff
      }
    }

    const file = png(width, height, 3, scanlines, palette)
    smallest = smallest === null || file.length < smallest.length ? file : smallest
  }

  return { file: smallest, colours: palette.length / 3 }
}

/** The LZW data of the indices of a GIF image, in sub-blocks (GIF89a, appendix F). */
function lzw(pixels, minimumCodeSize) {
  const clear = 1 << minimumCodeSize
  const end = clear + 1
  const bytes = []
  let codeSize = minimumCodeSize + 1
  let next = end + 1
  let table = new Map()
  let buffer = 0
  let bits = 0

  const emit = (code) => {
    buffer |= code << bits
    bits += codeSize

    while (bits >= 8) {
      bytes.push(buffer & 0xff)
      buffer >>>= 8
      bits -= 8
    }
  }

  emit(clear)
  let prefix = pixels[0]

  for (let index = 1; index < pixels.length; index++) {
    const pixel = pixels[index]
    const key = (prefix << 8) | pixel
    const code = table.get(key)

    if (code !== undefined) {
      prefix = code
      continue
    }

    emit(prefix)

    if (next === 4096) {
      emit(clear)
      table = new Map()
      codeSize = minimumCodeSize + 1
      next = end + 1
    } else {
      // The decoder widens its codes once its table reaches the current size.
      if (next >= 1 << codeSize) {
        codeSize++
      }

      table.set(key, next++)
    }

    prefix = pixel
  }

  emit(prefix)
  emit(end)

  if (bits > 0) {
    bytes.push(buffer & 0xff)
  }

  const blocks = [minimumCodeSize]

  for (let index = 0; index < bytes.length; index += 255) {
    const block = bytes.slice(index, index + 255)
    blocks.push(block.length, ...block)
  }

  blocks.push(0)

  return Buffer.from(blocks)
}

/** A GIF that loops forever: one palette of 255 colours and a transparent entry, each frame for its delay. */
function gif(frames) {
  const { width, height } = frames[0]
  const counts = new Map()

  for (const frame of frames) {
    histogram(frame.pixels, counts, 3)
  }

  const transparent = 255
  const palette = Buffer.alloc(256 * 3)
  quantize(counts, 255).copy(palette)
  const cache = new Map()
  const mapped = frames.map((frame) => ({ delay: frame.delay, pixels: indices(frame.pixels, palette.subarray(0, 255 * 3), cache) }))

  // Frames identical to the one before only add their time to it.
  const kept = []

  for (const frame of mapped) {
    const last = kept.at(-1)

    if (last && Buffer.compare(Buffer.from(last.pixels.buffer), Buffer.from(frame.pixels.buffer)) === 0) {
      last.delay += frame.delay
    } else {
      kept.push({ ...frame })
    }
  }

  const screen = Buffer.alloc(13)
  screen.write('GIF89a', 0, 'latin1')
  screen.writeUInt16LE(width, 6)
  screen.writeUInt16LE(height, 8)
  screen[10] = 0xf7
  const loop = Buffer.from([0x21, 0xff, 0x0b, ...Buffer.from('NETSCAPE2.0', 'latin1'), 0x03, 0x01, 0x00, 0x00, 0x00])
  const parts = [screen, palette, loop]
  let previous = null

  for (const frame of kept) {
    let left = 0
    let top = 0
    let right = width - 1
    let bottom = height - 1

    if (previous) {
      left = width
      top = height
      right = -1
      bottom = -1

      for (let y = 0; y < height; y++) {
        for (let x = 0; x < width; x++) {
          if (frame.pixels[y * width + x] !== previous[y * width + x]) {
            left = Math.min(left, x)
            right = Math.max(right, x)
            top = Math.min(top, y)
            bottom = Math.max(bottom, y)
          }
        }
      }
    }

    const boxWidth = right - left + 1
    const boxHeight = bottom - top + 1
    const region = new Uint8Array(boxWidth * boxHeight)

    for (let y = 0; y < boxHeight; y++) {
      for (let x = 0; x < boxWidth; x++) {
        const at = (top + y) * width + left + x
        region[y * boxWidth + x] = previous && frame.pixels[at] === previous[at] ? transparent : frame.pixels[at]
      }
    }

    const control = Buffer.from([0x21, 0xf9, 0x04, (1 << 2) | (previous ? 1 : 0), 0, 0, transparent, 0])
    control.writeUInt16LE(Math.max(2, Math.round(frame.delay / 10)), 4)
    const descriptor = Buffer.alloc(10)
    descriptor[0] = 0x2c
    descriptor.writeUInt16LE(left, 1)
    descriptor.writeUInt16LE(top, 3)
    descriptor.writeUInt16LE(boxWidth, 5)
    descriptor.writeUInt16LE(boxHeight, 7)
    parts.push(control, descriptor, lzw(region, 8))
    previous = frame.pixels
  }

  parts.push(Buffer.from([0x3b]))

  return { file: Buffer.concat(parts), frames: kept.length, duration: kept.reduce((total, frame) => total + frame.delay, 0) }
}

const kb = (bytes) => `${(bytes / 1024).toFixed(0)} KB`
const failures = []
const report = []

for (const name of readdirSync(join(raw, 'stills')).filter((file) => file.endsWith('.png')).sort()) {
  const image = decodePng(readFileSync(join(raw, 'stills', name)))
  let file = truecolourPng(image)
  let kind = 'lossless'

  for (const bits of [6, 5]) {
    if (file.length > STILL_LIMIT) {
      file = truecolourPng(rounded(image, bits))
      kind = `${bits}-bit channels`
    }
  }

  if (file.length > STILL_LIMIT) {
    const reduced = palettePng(image)
    file = reduced.file
    kind = `${reduced.colours} colours, diffused`
  }

  writeFileSync(join(assets, name), file)
  report.push(`${name.padEnd(24)} ${image.width}×${image.height}  ${kb(file.length).padStart(7)}  ${kind}`)

  if (file.length > STILL_LIMIT) {
    failures.push(`${name} is ${kb(file.length)}, over ${kb(STILL_LIMIT)}`)
  }
}

for (const name of readdirSync(join(raw, 'films')).filter((file) => file.endsWith('.json')).sort()) {
  const frames = JSON.parse(readFileSync(join(raw, 'films', name), 'utf8')).map(({ file, delay }) => ({ ...decodePng(readFileSync(file)), delay }))
  const result = gif(frames)
  const output = `${basename(name, '.json')}.gif`
  writeFileSync(join(assets, output), result.file)
  report.push(`${output.padEnd(24)} ${frames[0].width}×${frames[0].height}  ${kb(result.file.length).padStart(7)}  ${result.frames} frames, ${(result.duration / 1000).toFixed(1)} s`)

  if (result.file.length > GIF_LIMIT) {
    failures.push(`${output} is ${kb(result.file.length)}, over ${kb(GIF_LIMIT)}`)
  }
}

// The names are stable: an image of docs/assets that this run did not write is left over from another version of the script.
const written = new Set(report.map((line) => line.split(' ')[0]))

for (const name of readdirSync(assets).filter((file) => /\.(png|gif)$/.test(file))) {
  if (!written.has(name)) {
    failures.push(`${name} is in docs/assets but no screen of make screenshots writes it any longer: remove it`)
  }
}

console.log(report.join('\n'))

if (failures.length > 0) {
  console.error(failures.join('\n'))
  process.exit(1)
}
