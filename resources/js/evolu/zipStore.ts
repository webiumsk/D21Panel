/**
 * Minimal store-only (no compression) ZIP writer for client-side exports.
 * The repo carries no ZIP dependency and audit exports are small text
 * files - a dependency-free STORED archive keeps the bundle lean and the
 * output readable by every unzip tool. UTF-8 names (general-purpose flag
 * bit 11).
 */

export type ZipEntryInput = {
    name: string;
    content: string;
};

const textEncoder = new TextEncoder();

const CRC_TABLE = (() => {
    const table = new Uint32Array(256);
    for (let n = 0; n < 256; n += 1) {
        let c = n;
        for (let k = 0; k < 8; k += 1) {
            c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
        }
        table[n] = c >>> 0;
    }
    return table;
})();

export function crc32(bytes: Uint8Array): number {
    let crc = 0xffffffff;
    for (let i = 0; i < bytes.length; i += 1) {
        crc = CRC_TABLE[(crc ^ bytes[i]) & 0xff] ^ (crc >>> 8);
    }
    return (crc ^ 0xffffffff) >>> 0;
}

function dosDateTime(date: Date): { time: number; date: number } {
    const year = Math.max(1980, date.getFullYear());
    return {
        time: (date.getHours() << 11) | (date.getMinutes() << 5) | Math.floor(date.getSeconds() / 2),
        date: ((year - 1980) << 9) | ((date.getMonth() + 1) << 5) | date.getDate(),
    };
}

class ByteWriter {
    private chunks: Uint8Array[] = [];

    length = 0;

    u16(value: number): void {
        this.chunks.push(new Uint8Array([value & 0xff, (value >>> 8) & 0xff]));
        this.length += 2;
    }

    u32(value: number): void {
        this.chunks.push(
            new Uint8Array([
                value & 0xff,
                (value >>> 8) & 0xff,
                (value >>> 16) & 0xff,
                (value >>> 24) & 0xff,
            ]),
        );
        this.length += 4;
    }

    bytes(data: Uint8Array): void {
        this.chunks.push(data);
        this.length += data.length;
    }

    concat(): Uint8Array {
        const out = new Uint8Array(this.length);
        let offset = 0;
        for (const chunk of this.chunks) {
            out.set(chunk, offset);
            offset += chunk.length;
        }
        return out;
    }
}

/** Build a STORED ZIP archive from UTF-8 text entries. */
export function buildStoredZip(entries: ZipEntryInput[], createdAt: Date): Uint8Array {
    const { time, date } = dosDateTime(createdAt);
    const writer = new ByteWriter();
    const central: { name: Uint8Array; crc: number; size: number; offset: number }[] = [];

    for (const entry of entries) {
        const name = textEncoder.encode(entry.name);
        const data = textEncoder.encode(entry.content);
        const crc = crc32(data);
        const offset = writer.length;

        writer.u32(0x04034b50); // local file header
        writer.u16(20); // version needed
        writer.u16(0x0800); // UTF-8 names
        writer.u16(0); // method: stored
        writer.u16(time);
        writer.u16(date);
        writer.u32(crc);
        writer.u32(data.length);
        writer.u32(data.length);
        writer.u16(name.length);
        writer.u16(0); // extra length
        writer.bytes(name);
        writer.bytes(data);

        central.push({ name, crc, size: data.length, offset });
    }

    const centralStart = writer.length;
    for (const entry of central) {
        writer.u32(0x02014b50); // central directory header
        writer.u16(20); // version made by
        writer.u16(20); // version needed
        writer.u16(0x0800);
        writer.u16(0); // stored
        writer.u16(time);
        writer.u16(date);
        writer.u32(entry.crc);
        writer.u32(entry.size);
        writer.u32(entry.size);
        writer.u16(entry.name.length);
        writer.u16(0);
        writer.u16(0);
        writer.u16(0);
        writer.u16(0);
        writer.u32(0);
        writer.u32(entry.offset);
        writer.bytes(entry.name);
    }
    const centralSize = writer.length - centralStart;

    writer.u32(0x06054b50); // end of central directory
    writer.u16(0);
    writer.u16(0);
    writer.u16(central.length);
    writer.u16(central.length);
    writer.u32(centralSize);
    writer.u32(centralStart);
    writer.u16(0);

    return writer.concat();
}

export function zipBlob(entries: ZipEntryInput[], createdAt: Date): Blob {
    const bytes = buildStoredZip(entries, createdAt);
    const copy = new Uint8Array(bytes.length);
    copy.set(bytes);

    return new Blob([copy.buffer], { type: "application/zip" });
}
