// The tests of the image encoder of make screenshots (encode.mjs), with the test runner of Node.js and nothing else:
// the PNG it reads and writes, its LZW compression, its palette, the GIF with its frames that change nothing merged,
// the rectangles of the frames that do, their delays and the loop, and a whole run over a folder of captures. Each
// file it writes is read back here by a decoder written apart from it, from the PNG and GIF89a specifications.
//
//   node --test screenshots/encode.test.mjs (make test, through make test-tools)
import assert from 'node:assert/strict'
import { mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { describe, it } from 'node:test'
import { crc32, deflateSync, inflateSync } from 'node:zlib'
import { decodePng, encodeAssets, gif, indices, lzw, nearest, palettePng, png, PNG_SIGNATURE, quantize, rounded, truecolourPng } from './encode.mjs'

/** A generator of numbers that gives the same sequence on every run (mulberry32). */
function random(seed) {
  let state = seed

  return () => {
    state = (state + 0x6d2b79f5) | 0
    let value = Math.imul(state ^ (state >>> 15), 1 | state)
    value = (value + Math.imul(value ^ (value >>> 7), 61 | value)) ^ value

    return ((value ^ (value >>> 14)) >>> 0) / 4294967296
  }
}

/** An RGB image of these colours (0xRRGGBB), row by row. */
function image(width, height, colourAt) {
  const pixels = Buffer.alloc(width * height * 3)

  for (let y = 0; y < height; y++) {
    for (let x = 0; x < width; x++) {
      const colour = colourAt(x, y)
      pixels.writeUIntBE(colour, (y * width + x) * 3, 3)
    }
  }

  return { width, height, pixels }
}

/** The chunks of a PNG, each with whether its CRC is right. */
function chunks(file) {
  assert.ok(file.subarray(0, 8).equals(PNG_SIGNATURE), 'the PNG signature')
  const found = []

  for (let offset = 8; offset < file.length;) {
    const length = file.readUInt32BE(offset)
    const type = file.toString('latin1', offset + 4, offset + 8)
    const body = file.subarray(offset + 8, offset + 8 + length)
    const crc = file.readUInt32BE(offset + 8 + length)
    found.push({ type, body, crcOk: crc === crc32(body, crc32(Buffer.from(type, 'latin1'))) })
    offset += 12 + length
  }

  return found
}

/** One row filtered with a PNG filter (0 none, 1 sub, 2 up, 3 average, 4 Paeth), bytes per pixel `step`. */
function filterRow(filter, row, up, step) {
  const out = Buffer.alloc(row.length)

  for (let x = 0; x < row.length; x++) {
    const a = x >= step ? row[x - step] : 0
    const b = up ? up[x] : 0
    const c = up && x >= step ? up[x - step] : 0
    const p = a + b - c
    const paeth = Math.abs(p - a) <= Math.abs(p - b) && Math.abs(p - a) <= Math.abs(p - c) ? a : Math.abs(p - b) <= Math.abs(p - c) ? b : c
    const predictor = [0, a, b, (a + b) >> 1, paeth][filter]
    out[x] = (row[x] - predictor) & 0xff
  }

  return out
}

/** The pixels of a palette PNG (colour type 3, filters none, sub and up), as RGB: the reading side of palettePng. */
function decodePalettePng(file) {
  const found = chunks(file)
  const header = found.find((chunk) => chunk.type === 'IHDR').body
  const width = header.readUInt32BE(0)
  const height = header.readUInt32BE(4)
  assert.equal(header[9], 3, 'colour type 3, a palette')
  const palette = found.find((chunk) => chunk.type === 'PLTE').body
  const data = inflateSync(Buffer.concat(found.filter((chunk) => chunk.type === 'IDAT').map((chunk) => chunk.body)))
  const index = new Uint8Array(width * height)

  for (let y = 0; y < height; y++) {
    const filter = data[y * (width + 1)]
    assert.ok(filter <= 2, `filter ${filter}`)

    for (let x = 0; x < width; x++) {
      const predictor = filter === 1 ? (x > 0 ? index[y * width + x - 1] : 0) : filter === 2 ? (y > 0 ? index[(y - 1) * width + x] : 0) : 0
      index[y * width + x] = (data[y * (width + 1) + 1 + x] + predictor) & 0xff
    }
  }

  return { width, height, pixels: Buffer.from([...index].flatMap((entry) => [palette[entry * 3], palette[entry * 3 + 1], palette[entry * 3 + 2]])) }
}

/** The indices of LZW data in sub-blocks, as a decoder of GIF89a (appendix F) reads them. */
function unlzw(data) {
  const minimumCodeSize = data[0]
  const bytes = []
  let offset = 1

  for (;;) {
    const size = data[offset++]
    assert.ok(size !== undefined, 'the sub-blocks end with an empty one')

    if (size === 0) {
      break
    }

    assert.ok(size <= 255, 'a sub-block holds at most 255 bytes')
    bytes.push(...data.subarray(offset, offset + size))
    offset += size
  }

  assert.equal(offset, data.length, 'nothing after the last sub-block')

  const clear = 1 << minimumCodeSize
  const end = clear + 1
  let table = []
  let codeSize = minimumCodeSize + 1
  const reset = () => {
    table = Array.from({ length: clear }, (_, value) => [value])
    table.push(null, null)
    codeSize = minimumCodeSize + 1
  }
  let buffer = 0
  let bits = 0
  let position = 0
  const read = () => {
    while (bits < codeSize) {
      assert.ok(position < bytes.length, 'the data ends with the end code')
      buffer |= bytes[position++] << bits
      bits += 8
    }

    const code = buffer & ((1 << codeSize) - 1)
    buffer >>>= codeSize
    bits -= codeSize

    return code
  }
  const out = []
  let previous = null
  let resets = 0

  reset()
  assert.equal(read(), clear, 'the data starts with a clear code')

  for (;;) {
    const code = read()

    if (code === clear) {
      reset()
      resets++
      previous = null
      continue
    }

    if (code === end) {
      break
    }

    const entry = code < table.length && table[code] !== null ? table[code] : code === table.length && previous ? [...previous, previous[0]] : null
    assert.ok(entry, `code ${code} is known`)
    out.push(...entry)

    if (previous && table.length < 4096) {
      table.push([...previous, entry[0]])

      if (table.length === 1 << codeSize && codeSize < 12) {
        codeSize++
      }
    }

    previous = entry
  }

  return { indices: Uint8Array.from(out), resets }
}

/** A GIF89a read back: its screen, palette, loop, and each frame as its rectangle, control and indices. */
function readGif(file) {
  assert.equal(file.toString('latin1', 0, 6), 'GIF89a')
  const width = file.readUInt16LE(6)
  const height = file.readUInt16LE(8)
  const packed = file[10]
  assert.ok(packed & 0x80, 'a global colour table')
  const paletteSize = 2 << (packed & 0x07)
  const palette = file.subarray(13, 13 + paletteSize * 3)
  const frames = []
  let loop = null
  let control = null
  let offset = 13 + paletteSize * 3

  const subBlocks = () => {
    const start = offset

    while (file[offset] !== 0) {
      offset += file[offset] + 1
    }

    offset++

    return file.subarray(start, offset)
  }

  for (;;) {
    const introducer = file[offset++]

    if (introducer === 0x3b) {
      break
    }

    if (introducer === 0x21) {
      const label = file[offset++]

      if (label === 0xf9) {
        assert.equal(file[offset], 4, 'a graphic control block of 4 bytes')
        const flags = file[offset + 1]
        control = { disposal: (flags >> 2) & 0x07, transparent: flags & 0x01 ? file[offset + 4] : null, delay: file.readUInt16LE(offset + 2) }
        offset += 5
        assert.equal(file[offset++], 0, 'the graphic control block ends')
      } else if (label === 0xff) {
        const identifier = file.toString('latin1', offset + 1, offset + 12)
        offset += 12
        const data = subBlocks()

        if (identifier === 'NETSCAPE2.0') {
          loop = data.readUInt16LE(2)
        }
      } else {
        subBlocks()
      }

      continue
    }

    assert.equal(introducer, 0x2c, 'an image descriptor')
    const box = {
      left: file.readUInt16LE(offset),
      top: file.readUInt16LE(offset + 2),
      width: file.readUInt16LE(offset + 4),
      height: file.readUInt16LE(offset + 6),
    }
    assert.equal(file[offset + 8] & 0x80, 0, 'no local colour table')
    offset += 9
    const start = offset
    offset++
    subBlocks()
    frames.push({ ...box, control, ...unlzw(file.subarray(start, offset)) })
    control = null
  }

  assert.equal(offset, file.length, 'nothing after the trailer')

  return { width, height, palette, loop, frames }
}

/** The frames of a GIF drawn one over the other, as a viewer shows them, in RGB. */
function play({ width, height, palette, frames }) {
  const canvas = new Uint8Array(width * height)

  return frames.map((frame) => {
    for (let y = 0; y < frame.height; y++) {
      for (let x = 0; x < frame.width; x++) {
        const entry = frame.indices[y * frame.width + x]

        if (entry !== frame.control.transparent) {
          canvas[(frame.top + y) * width + frame.left + x] = entry
        }
      }
    }

    return Buffer.from([...canvas].flatMap((entry) => [palette[entry * 3], palette[entry * 3 + 1], palette[entry * 3 + 2]]))
  })
}

describe('the PNG files', () => {
  const picture = image(23, 17, (x, y) => ((x * 11) << 16) | ((y * 15) << 8) | ((x * y) & 0xff))

  it('writes a lossless RGB PNG that reads back pixel for pixel, its chunks in order with their CRCs', () => {
    const file = truecolourPng(picture)
    const found = chunks(file)

    assert.deepEqual(
      found.map((chunk) => chunk.type),
      ['IHDR', 'IDAT', 'IEND'],
    )
    assert.ok(
      found.every((chunk) => chunk.crcOk),
      'every CRC',
    )
    const header = found[0].body
    assert.deepEqual([header.readUInt32BE(0), header.readUInt32BE(4), header[8], header[9], header[12]], [23, 17, 8, 2, 0])
    assert.deepEqual(decodePng(file), picture)
  })

  it('reads the RGBA PNG of the browser with each of the five filters, and drops the alpha', () => {
    const width = 9
    const height = 10
    const rgba = Buffer.alloc(width * height * 4)
    const next = random(7)

    for (let index = 0; index < rgba.length; index++) {
      rgba[index] = Math.floor(next() * 256)
    }

    const stride = width * 4
    const scanlines = Buffer.concat(
      Array.from({ length: height }, (_, y) => {
        const filter = y % 5
        const row = rgba.subarray(y * stride, (y + 1) * stride)
        const up = y > 0 ? rgba.subarray((y - 1) * stride, y * stride) : null

        return Buffer.concat([Buffer.from([filter]), filterRow(filter, row, up, 4)])
      }),
    )
    const decoded = decodePng(png(width, height, 6, scanlines))

    assert.equal(decoded.width, width)
    assert.equal(decoded.height, height)
    assert.deepEqual(
      decoded.pixels,
      Buffer.from([...Array(width * height).keys()].flatMap((pixel) => [rgba[pixel * 4], rgba[pixel * 4 + 1], rgba[pixel * 4 + 2]])),
    )
  })

  it('refuses what is not a PNG of the browser', () => {
    assert.throws(() => decodePng(Buffer.from('GIF89a and more')), /not a PNG/)
    const header = Buffer.alloc(13)
    header.writeUInt32BE(1, 0)
    header.writeUInt32BE(1, 4)
    header[8] = 16
    header[9] = 2
    const sixteenBits = Buffer.concat([PNG_SIGNATURE, Buffer.from([0, 0, 0, 13]), Buffer.from('IHDR', 'latin1'), header, Buffer.alloc(4)])
    assert.throws(() => decodePng(sixteenBits), /bit depth 16/)
  })

  it('rounds the channels to fewer bits, never past 255', () => {
    const shades = image(4, 1, (x) => [0x010203, 0x7f7f7f, 0xfefefe, 0xffffff][x])

    assert.deepEqual([...rounded(shades, 6).pixels], [0, 4, 4, 128, 128, 128, 255, 255, 255, 255, 255, 255])
    assert.ok([...rounded(shades, 5).pixels].every((value) => value % 8 === 0 || value === 255))
  })

  it('writes a palette PNG that keeps every colour of an image with fewer than 256', () => {
    const colours = [0xffffff, 0x111827, 0x4f46e5, 0x10b981, 0xef4444]
    const flat = image(40, 30, (x, y) => colours[(Math.floor(x / 8) + Math.floor(y / 6)) % colours.length])
    const { file, colours: count } = palettePng(flat)

    assert.equal(count, colours.length)
    assert.ok(
      chunks(file).every((chunk) => chunk.crcOk),
      'every CRC',
    )
    assert.deepEqual(decodePalettePng(file), flat)
  })
})

describe('the LZW compression of the GIF images', () => {
  const roundTrip = (indicesToWrite) => {
    const { indices: read, resets } = unlzw(lzw(indicesToWrite, 8))

    assert.deepEqual(read, indicesToWrite)

    return resets
  }

  it('reads back one pixel, a run of one colour and every index once', () => {
    roundTrip(Uint8Array.from([42]))
    roundTrip(new Uint8Array(5000).fill(255))
    roundTrip(Uint8Array.from({ length: 256 }, (_, index) => index))
  })

  it('reads back a screen of a few colours, and a noisy one that fills the table of 4096 codes and clears it', () => {
    const next = random(42)
    const few = Uint8Array.from({ length: 300_000 }, () => (next() < 0.9 ? 0 : Math.floor(next() * 6)))
    const noisy = Uint8Array.from({ length: 60_000 }, () => Math.floor(next() * 256))

    roundTrip(few)
    assert.ok(roundTrip(noisy) > 0, 'the table filled up and was cleared')
  })
})

describe('the palette', () => {
  it('keeps the colours as they are when there are no more than the palette holds', () => {
    const counts = new Map([
      [0xffffff, 900],
      [0x000000, 50],
      [0x4f46e5, 10],
    ])

    assert.deepEqual([...quantize(counts, 255)], [255, 255, 255, 0, 0, 0, 0x4f, 0x46, 0xe5])
  })

  it('brings many colours down to the size asked, each near the colours it stands for', () => {
    const next = random(3)
    const counts = new Map()

    // Two clouds of shades: around a light grey and around an indigo.
    for (let index = 0; index < 4000; index++) {
      const [r, g, b] = index % 2 === 0 ? [230, 230, 230] : [79, 70, 229]
      const shade = (value) => Math.max(0, Math.min(255, value + Math.round((next() - 0.5) * 12)))
      const key = (shade(r) << 16) | (shade(g) << 8) | shade(b)
      counts.set(key, (counts.get(key) ?? 0) + 1)
    }

    const palette = quantize(counts, 2)

    assert.equal(palette.length, 6)
    const entries = [0, 1].map((entry) => [palette[entry * 3], palette[entry * 3 + 1], palette[entry * 3 + 2]])
    const near = (entry, target) => entry.every((value, channel) => Math.abs(value - target[channel]) <= 3)
    assert.ok(entries.some((entry) => near(entry, [230, 230, 230])) && entries.some((entry) => near(entry, [79, 70, 229])), JSON.stringify(entries))
  })

  it('maps each pixel to the nearest entry', () => {
    const palette = Buffer.from([255, 255, 255, 0, 0, 0, 79, 70, 229])

    assert.equal(nearest(palette, 3, 0xf0f0f0), 0)
    assert.equal(nearest(palette, 3, 0x101010), 1)
    assert.equal(nearest(palette, 3, 0x5046e0), 2)
    assert.deepEqual([...indices(Buffer.from([250, 250, 250, 70, 70, 220, 5, 5, 5]), palette, new Map())], [0, 2, 1])
  })
})

describe('the GIF', () => {
  const colours = [0xfafafa, 0x111827, 0x4f46e5, 0x10b981]
  const base = image(12, 8, (x, y) => colours[(x + y) % 2])
  const changed = (from, rectangle, colour) => {
    const copy = { ...from, pixels: Buffer.from(from.pixels) }

    for (let y = rectangle.top; y < rectangle.top + rectangle.height; y++) {
      for (let x = rectangle.left; x < rectangle.left + rectangle.width; x++) {
        copy.pixels.writeUIntBE(colour, (y * from.width + x) * 3, 3)
      }
    }

    return copy
  }
  const second = changed(base, { left: 3, top: 2, width: 2, height: 3 }, colours[2])
  const third = changed(second, { left: 9, top: 6, width: 3, height: 2 }, colours[3])
  const frames = [
    { ...base, delay: 1100 },
    { ...base, delay: 350 },
    { ...second, delay: 110 },
    { ...third, delay: 5 },
  ]
  const result = gif(frames)
  const read = readGif(result.file)

  it('loops for ever, with one palette of 256 entries', () => {
    assert.equal(read.loop, 0)
    assert.equal(read.palette.length, 256 * 3)
    assert.deepEqual([read.width, read.height], [12, 8])
  })

  it('merges a frame that changes nothing into the one before, its time added', () => {
    assert.equal(result.frames, 3)
    assert.equal(read.frames.length, 3)
    assert.equal(result.duration, 1100 + 350 + 110 + 5)
  })

  it('gives each frame its delay in hundredths of a second, two at least, and keeps what it does not cover', () => {
    assert.deepEqual(
      read.frames.map((frame) => frame.control.delay),
      [145, 11, 2],
    )
    assert.ok(read.frames.every((frame) => frame.control.disposal === 1))
  })

  it('writes the first frame whole and each later one as the rectangle that changed, its unchanged pixels transparent', () => {
    assert.deepEqual(
      read.frames.map(({ left, top, width, height }) => [left, top, width, height]),
      [
        [0, 0, 12, 8],
        [3, 2, 2, 3],
        [9, 6, 3, 2],
      ],
    )
    assert.equal(read.frames[0].control.transparent, null)
    assert.equal(read.frames[1].control.transparent, 255)
    assert.deepEqual(play(read), [base.pixels, second.pixels, third.pixels])
  })
})

describe('a run of make screenshots', () => {
  it('writes each screenshot and each GIF of the captures, and reports an image no screen writes any longer', () => {
    const root = mkdtempSync(join(tmpdir(), 'encode-test-'))

    try {
      const raw = join(root, 'raw')
      const assets = join(root, 'assets')
      mkdirSync(join(raw, 'stills'), { recursive: true })
      mkdirSync(join(raw, 'films', 'clip'), { recursive: true })
      mkdirSync(assets)

      // A still of noise over the 400 KB of a lossless PNG: the first smaller way that fits is taken.
      const next = random(11)
      const noise = image(380, 380, () => Math.floor(next() * 0x1000000))
      writeFileSync(join(raw, 'stills', 'noise.png'), truecolourPng(noise))
      writeFileSync(join(raw, 'stills', 'flat.png'), truecolourPng(image(64, 48, (x) => (x < 32 ? 0xffffff : 0x4f46e5))))
      const frames = [image(16, 10, () => 0xffffff), image(16, 10, (x, y) => (x === 4 && y === 5 ? 0x111827 : 0xffffff))].map((frame, index) => {
        const file = join(raw, 'films', 'clip', `${index}.png`)
        writeFileSync(file, truecolourPng(frame))

        return { file, delay: 500 }
      })
      writeFileSync(join(raw, 'films', 'clip.json'), JSON.stringify(frames))
      writeFileSync(join(assets, 'retired.png'), truecolourPng(image(2, 2, () => 0)))

      const { report, failures } = encodeAssets(raw, assets)

      assert.deepEqual(
        report.map((line) => line.split(/\s+/)[0]),
        ['flat.png', 'noise.png', 'clip.gif'],
      )
      assert.match(report[0], /64×48 .* lossless$/)
      assert.match(report[1], /380×380 .* 6-bit channels$/)
      assert.match(report[2], /16×10 .* 2 frames, 1\.0 s$/)
      assert.deepEqual(decodePng(readFileSync(join(assets, 'flat.png'))).pixels, image(64, 48, (x) => (x < 32 ? 0xffffff : 0x4f46e5)).pixels)
      assert.equal(readGif(readFileSync(join(assets, 'clip.gif'))).frames.length, 2)
      assert.deepEqual(failures, ['retired.png is in docs/assets but no screen of make screenshots writes it any longer: remove it'])
    } finally {
      rmSync(root, { recursive: true, force: true })
    }
  })
})

// deflateSync is what png() compresses with; a sanity check that the runtime of the tests has it as the encoder does.
it('has the zlib of Node.js the encoder relies on', () => {
  assert.equal(inflateSync(deflateSync(Buffer.from('dian-einvoice'))).toString(), 'dian-einvoice')
})
