<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace local_omeroembed;

/**
 * Stitches several independent single-frame GIFs (as produced by
 * heatmap_renderer::render_frame(), one per capture_heatmap_frames.php run)
 * into one animated GIF - the classic technique for building an animated
 * GIF with no external tools (no ffmpeg, no Imagick - this server has
 * neither, see heatmap_renderer.php's own docblock): take the first
 * frame's header/Logical Screen Descriptor verbatim, add a Netscape2.0
 * Application Extension for infinite looping, then for every frame splice
 * in a fresh Graphic Control Extension (for the display delay) followed by
 * that frame's own Image Descriptor + Colour Table + Image Data.
 *
 * Each frame's own colour table is always carried forward as a *local*
 * colour table on its own Image Descriptor, never assumed to match any
 * other frame's - confirmed live this matters: GD's imagegif() computes an
 * independent, image-specific Global Colour Table per call (there's no
 * reason two frames with different heatmap colours would end up with the
 * same palette), so an earlier version of this class that discarded every
 * frame's table except the first one's rendered every frame after the
 * first with the wrong colours (verified with Pillow: `n_frames` was
 * correct, but every frame decoded to the first frame's own colour -
 * exactly this bug). If a frame already carries its own Local Colour Table
 * (not something GD emits, but not assumed impossible), that's left alone
 * instead of being overwritten.
 *
 * @package    local_omeroembed
 * @copyright  2026 University of Glasgow MVLS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class gif_encoder {
    /** GIF Extension Introducer block byte. */
    const EXTENSION_INTRODUCER = 0x21;

    /** GIF Image Descriptor Introducer block byte. */
    const IMAGE_DESCRIPTOR_INTRODUCER = 0x2C;

    /** GIF Trailer block byte, marking end of file. */
    const TRAILER = 0x3B;

    /**
     * Assembles a set of single-frame GIFs into one animated GIF.
     *
     * @param string[] $rawgifframes Raw single-frame GIF bytes, oldest first.
     * @param int $delaycentiseconds Display duration per frame, in 1/100s.
     * @return string Raw bytes of one animated GIF file.
     * @throws \moodle_exception If $rawgifframes is empty, or a frame
     *                            doesn't parse as a well-formed GIF.
     */
    public static function assemble(array $rawgifframes, int $delaycentiseconds = 60): string {
        if (empty($rawgifframes)) {
            throw new \moodle_exception('novideoframes', 'local_omeroembed');
        }

        $first = self::split_frame($rawgifframes[0]);
        // The Logical Screen Descriptor (dimensions) is shared, but every
        // frame supplies its own local colour table below regardless of
        // whether this shared header happened to include a global one -
        // see this class's own docblock for why that's not optional.
        $out = $first['headerandlsd'];

        // Netscape2.0 Application Extension: 0x21 0xFF, block size 11,
        // "NETSCAPE2.0", sub-block size 3, sub-block id 1, loop count as a
        // 2-byte little-endian value (0 = loop forever), block terminator.
        $out .= "\x21\xFF\x0B" . 'NETSCAPE2.0' . "\x03\x01\x00\x00\x00";

        foreach ($rawgifframes as $rawframe) {
            $parts = self::split_frame($rawframe);

            // Graphic Control Extension: 0x21 0xF9, block size 4, packed
            // fields (no transparency, disposal method 0 - "unspecified",
            // fine here since every frame fully repaints the whole canvas),
            // delay as a 2-byte little-endian value, transparent colour
            // index (unused), block terminator.
            $out .= "\x21\xF9\x04" . "\x00"
                . chr($delaycentiseconds & 0xFF) . chr(($delaycentiseconds >> 8) & 0xFF)
                . "\x00\x00";
            $out .= $parts['descriptor'] . $parts['colourtable'] . $parts['data'];
        }

        $out .= chr(self::TRAILER);
        return $out;
    }

    /**
     * Splits one raw GIF's bytes into everything needed to re-emit it as
     * one frame of an animated GIF, always carrying its own colour table
     * forward as a Local Colour Table on its own Image Descriptor.
     *
     * @param string $bytes
     * @return array{headerandlsd: string, descriptor: string, colourtable: string, data: string}
     * @throws \moodle_exception If $bytes doesn't look like a real GIF.
     */
    private static function split_frame(string $bytes): array {
        if (strlen($bytes) < 13 || substr($bytes, 0, 3) !== 'GIF') {
            throw new \moodle_exception('invalidgifframe', 'local_omeroembed');
        }

        // Header (6 bytes: "GIF87a"/"GIF89a") + Logical Screen Descriptor
        // (7 bytes: width, height, packed fields, background colour index,
        // pixel aspect ratio).
        $lsdpacked = ord($bytes[10]);

        $offset = 13;
        $globalcolourtable = '';
        $globalsizebits = 0;
        if ($lsdpacked & 0x80) {
            // Global Colour Table Flag set - table size is 2^(N+1) entries
            // of 3 bytes (RGB) each, N being the low 3 bits of $lsdpacked.
            $globalsizebits = $lsdpacked & 0x07;
            $gctsize = 3 * (2 ** ($globalsizebits + 1));
            $globalcolourtable = substr($bytes, $offset, $gctsize);
            $offset += $gctsize;
        }

        // The headerandlsd value is only ever used for the *first* frame, as the
        // shared file-level header - and since every frame's own colour
        // table travels with it as a Local Colour Table instead (see this
        // class's own docblock for why), this clears the Global Colour
        // Table Flag bit so it accurately says "no global table follows
        // here", rather than a stale flag claiming one does when this
        // class deliberately never emits one at the file level.
        $headerandlsd = substr($bytes, 0, 10) . chr($lsdpacked & 0x7F) . substr($bytes, 11, 2);

        // Walk blocks from $offset, skipping any Extension blocks GD may
        // have written (imagegif() doesn't normally emit one for a plain
        // single-frame image, but this doesn't assume that) to find the
        // Image Descriptor.
        $pos = $offset;
        $len = strlen($bytes);
        while ($pos < $len) {
            $introducer = ord($bytes[$pos]);
            if ($introducer === self::EXTENSION_INTRODUCER) {
                $pos += 2; // Skip the introducer byte and the label byte.
                while ($pos < $len) {
                    $blocksize = ord($bytes[$pos]);
                    $pos += 1 + $blocksize;
                    if ($blocksize === 0) {
                        break;
                    }
                }
            } else {
                break; // Image Descriptor (0x2C) or Trailer (0x3B) - either way, stop here.
            }
        }

        // Image Descriptor: introducer(1) + left(2) + top(2) + width(2) + height(2) + packed(1) = 10 bytes.
        $descriptor = substr($bytes, $pos, 10);
        $descriptorpacked = ord($descriptor[9]);
        $dataoffset = $pos + 10;

        if ($descriptorpacked & 0x80) {
            // This frame already has its own Local Colour Table - leave it
            // and the descriptor exactly as they are.
            $localsizebits = $descriptorpacked & 0x07;
            $ctsize = 3 * (2 ** ($localsizebits + 1));
            $colourtable = substr($bytes, $dataoffset, $ctsize);
            $dataoffset += $ctsize;
        } else {
            // No local table - promote this frame's own Global Colour
            // Table into a Local one, so this frame's pixel indices are
            // always paired with the palette they were actually computed
            // against, regardless of what the shared header/first frame's
            // table looks like (see this class's own docblock for why).
            $colourtable = $globalcolourtable;
            $descriptorpacked |= 0x80; // Set Local Colour Table Flag.
            $descriptorpacked = ($descriptorpacked & 0xF8) | $globalsizebits; // Match its size bits.
            $descriptor = substr($descriptor, 0, 9) . chr($descriptorpacked);
        }

        // Everything from the LZW minimum code size up to (but not
        // including) the Trailer byte.
        $dataend = ($len > 0 && ord($bytes[$len - 1]) === self::TRAILER) ? $len - 1 : $len;
        $data = substr($bytes, $dataoffset, max(0, $dataend - $dataoffset));

        return [
            'headerandlsd' => $headerandlsd,
            'descriptor' => $descriptor,
            'colourtable' => $colourtable,
            'data' => $data,
        ];
    }
}
