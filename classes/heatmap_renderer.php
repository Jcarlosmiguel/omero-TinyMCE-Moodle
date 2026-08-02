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
 * Server-side heatmap frame rendering for the downloadable video feature -
 * runs entirely in PHP/GD, no browser involved, so it works from a
 * scheduled task (classes/task/capture_heatmap_frames.php) with nobody's
 * browser open. Two independent halves:
 *
 * - fetch_base_image_for_embed(): gets a real, whole-slide static image via
 *   OMERO's webgateway render_thumbnail endpoint (confirmed live: a plain
 *   authenticated GET, same omero_session credentials proxy.php already
 *   uses - no tiled OpenLayers viewer involved at all), since nothing else
 *   records which OMERO subject/image an embedid points to except the
 *   sourceurl this parses out of local_omeroembed_embed_tracking (see that
 *   table's own comment).
 * - render_frame(): draws the same blob-radius + colour-ramp density
 *   technique js/heatmap-view.js already uses, reimplemented in plain PHP
 *   arrays (GD has no additive/"lighter" blend mode to lean on) on a
 *   deliberately coarse accumulation grid, then resized up onto
 *   the base image - keeps per-frame cost low even at
 *   tracking_repository::MAX_SAMPLES.
 *
 * @package    local_omeroembed
 * @copyright  2026 University of Glasgow MVLS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class heatmap_renderer {
    /** @var int Max dimension requested from OMERO's render_thumbnail - confirmed live this returns a real, reasonably large whole-slide image. */
    const BASE_IMAGE_SIZE = 1200;

    /** @var int Width of the density accumulation grid - deliberately coarser than the display image, see this class's own docblock. */
    const GRID_WIDTH = 200;

    /** @var int Blob radius in grid units. */
    const BLOB_RADIUS_GRID = 10;

    /** @var array|null Cached colour ramp - see colour_ramp(). */
    private static $colourramp = null;

    /**
     * Fetches a real whole-slide base image for one embed placement, by
     * parsing which OMERO subject/image it points to out of its stored
     * sourceurl (see local_omeroembed_embed_tracking's own comment on that
     * field) and requesting a render_thumbnail through the same
     * subject-authenticated session proxy.php itself uses.
     *
     * Returns null (not an exception) for anything this can't handle -
     * a dataset-based/browsable embed with no single fixed image is
     * ambiguous to render a base frame from, so the capture task simply
     * skips it rather than failing the whole run over one embed.
     *
     * @param string $embedid
     * @return array{jpeg: string, nativewidth: int, nativeheight: int}|null
     */
    public static function fetch_base_image_for_embed(string $embedid): ?array {
        global $DB;

        $tracking = $DB->get_record('local_omeroembed_embed_tracking', ['embedid' => $embedid]);
        if (!$tracking || !$tracking->sourceurl) {
            return null;
        }

        $parsed = self::parse_sourceurl($tracking->sourceurl);
        if (!$parsed) {
            return null;
        }

        $baseurl = rtrim((string) get_config('local_omeroembed', 'omerobaseurl'), '/');
        $session = omero_session::get_session($parsed['subject']);

        $dimensions = self::fetch_image_dimensions($baseurl, $session, $parsed['imageid']);
        if (!$dimensions) {
            return null;
        }

        $jpeg = self::curl_get(
            $baseurl . '/webgateway/render_thumbnail/' . $parsed['imageid'] . '/' . self::BASE_IMAGE_SIZE . '/',
            $session
        );
        if ($jpeg === null) {
            return null;
        }

        return [
            'jpeg' => $jpeg,
            'nativewidth' => $dimensions['width'],
            'nativeheight' => $dimensions['height'],
        ];
    }

    /**
     * Pulls the OMERO subject key and image id out of a stored sourceurl
     * (e.g. ".../proxy.php/3/oral_biology?images=1908") - the same URL
     * shape author.php's own $proxyurl builds, see that file's own
     * comments for the courseid/subject-as-path-segments convention.
     *
     * @param string $sourceurl
     * @return array{subject: string, imageid: int}|null
     */
    private static function parse_sourceurl(string $sourceurl): ?array {
        $parts = parse_url($sourceurl);
        if (!isset($parts['path']) || !preg_match('#/proxy\.php/\d+/([A-Za-z0-9_]+)#', $parts['path'], $m)) {
            return null;
        }
        $subject = $m[1];

        $query = [];
        if (isset($parts['query'])) {
            parse_str($parts['query'], $query);
        }
        if (empty($query['images'])) {
            return null;
        }
        // 'images' can be a comma-separated PARAM_SEQUENCE (author.php's
        // own browsable-dataset case) - a video frame can only show one
        // base image, so just take the first.
        $imageid = (int) explode(',', (string) $query['images'])[0];
        if ($imageid <= 0) {
            return null;
        }

        return ['subject' => $subject, 'imageid' => $imageid];
    }

    /**
     * @param string $baseurl
     * @param array{cookie: string, csrftoken: string} $session
     * @param int $imageid
     * @return array{width: int, height: int}|null
     */
    private static function fetch_image_dimensions(string $baseurl, array $session, int $imageid): ?array {
        $body = self::curl_get($baseurl . '/webgateway/imgData/' . $imageid . '/', $session);
        if ($body === null) {
            return null;
        }
        $data = json_decode($body, true);
        if (!isset($data['size']['width'], $data['size']['height'])) {
            return null;
        }
        return ['width' => (int) $data['size']['width'], 'height' => (int) $data['size']['height']];
    }

    /**
     * @param string $url
     * @param array{cookie: string, csrftoken: string} $session
     * @return string|null Response body, or null on any non-200/curl failure.
     */
    private static function curl_get(string $url, array $session): ?string {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Cookie: ' . $session['cookie']]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $status !== 200) {
            return null;
        }
        return $body;
    }

    /**
     * Renders one heatmap frame: the given base slide image with a
     * translucent density overlay composited on top, returned as raw
     * single-frame GIF bytes (classes/gif_encoder.php later stitches many
     * of these together).
     *
     * @param string $basejpeg Raw JPEG bytes, as returned by fetch_base_image_for_embed().
     * @param array $samples Rows with ->x, ->y (real image-pixel coordinates - see tracking_repository::get_samples()).
     * @param int $nativewidth The OMERO image's real full-resolution width (for coordinate scaling).
     * @param int $nativeheight
     * @return string|null Raw GIF bytes, or null if the base image couldn't be decoded.
     */
    public static function render_frame(string $basejpeg, array $samples, int $nativewidth, int $nativeheight): ?string {
        $im = @imagecreatefromstring($basejpeg);
        if (!$im) {
            return null;
        }

        $displaywidth = imagesx($im);
        $displayheight = imagesy($im);

        $gridwidth = self::GRID_WIDTH;
        $gridheight = max(1, (int) round($gridwidth * $displayheight / $displaywidth));
        $grid = array_fill(0, $gridwidth * $gridheight, 0.0);

        $scalex = $nativewidth > 0 ? $gridwidth / $nativewidth : 0;
        $scaley = $nativeheight > 0 ? $gridheight / $nativeheight : 0;
        $radius = self::BLOB_RADIUS_GRID;

        foreach ($samples as $sample) {
            $gx = (int) round($sample->x * $scalex);
            $gy = (int) round($sample->y * $scaley);
            for ($dx = -$radius; $dx <= $radius; $dx++) {
                $px = $gx + $dx;
                if ($px < 0 || $px >= $gridwidth) {
                    continue;
                }
                for ($dy = -$radius; $dy <= $radius; $dy++) {
                    $py = $gy + $dy;
                    if ($py < 0 || $py >= $gridheight) {
                        continue;
                    }
                    $dist = sqrt($dx * $dx + $dy * $dy);
                    if ($dist > $radius) {
                        continue;
                    }
                    // Same soft radial falloff as the blob sprite the JS
                    // renderers stamp per sample, just computed directly
                    // into the accumulation grid rather than via a
                    // pre-rendered sprite image.
                    $grid[$py * $gridwidth + $px] += (1 - ($dist / $radius)) * 90;
                }
            }
        }

        $ramp = self::colour_ramp();

        // Drawn with alpha blending OFF so each pixel's *own* alpha value
        // is stored as-given, not blended against whatever the overlay's
        // (transparent) background already was - the standard GD idiom for
        // building a translucent layer to composite later.
        $overlay = imagecreatetruecolor($gridwidth, $gridheight);
        imagealphablending($overlay, false);
        imagesavealpha($overlay, true);
        $transparent = imagecolorallocatealpha($overlay, 0, 0, 0, 127);
        imagefill($overlay, 0, 0, $transparent);

        foreach ($grid as $idx => $value) {
            if ($value <= 0) {
                continue;
            }
            $alpha = min(200, $value + 60);
            $rampindex = min(255, (int) $value);
            [$r, $g, $b] = $ramp[$rampindex];
            // GD alpha is inverted vs canvas/CSS: 0 = fully opaque, 127 =
            // fully transparent.
            $gdalpha = max(0, 127 - (int) round($alpha / 200 * 127));
            $x = $idx % $gridwidth;
            $y = (int) ($idx / $gridwidth);
            $colour = imagecolorallocatealpha($overlay, $r, $g, $b, $gdalpha);
            imagesetpixel($overlay, $x, $y, $colour);
        }

        // Scaled up to the base image's real size - imagecopyresampled()
        // interpolates, which also softens the coarse grid into something
        // closer to the smooth canvas version rather than visibly blocky.
        $overlayscaled = imagecreatetruecolor($displaywidth, $displayheight);
        imagealphablending($overlayscaled, false);
        imagesavealpha($overlayscaled, true);
        $transparent2 = imagecolorallocatealpha($overlayscaled, 0, 0, 0, 127);
        imagefill($overlayscaled, 0, 0, $transparent2);
        imagecopyresampled(
            $overlayscaled, $overlay,
            0, 0, 0, 0,
            $displaywidth, $displayheight, $gridwidth, $gridheight
        );

        // Real alpha compositing onto the base image (blending ON on the
        // destination this time) - after this, the effect is "baked in" to
        // $im as ordinary opaque colour, so converting to GIF (which can't
        // represent partial transparency) below loses nothing that matters.
        imagealphablending($im, true);
        imagecopy($im, $overlayscaled, 0, 0, 0, 0, $displaywidth, $displayheight);

        ob_start();
        imagegif($im);
        $bytes = ob_get_clean();

        imagedestroy($im);
        imagedestroy($overlay);
        imagedestroy($overlayscaled);

        return $bytes === false ? null : $bytes;
    }

    /**
     * Hand-rolled blue -> cyan -> green -> yellow -> red ramp - a direct
     * PHP port of js/heatmap-view.js's buildColourRamp(), index 0..255
     * mapping accumulated density to an RGB colour.
     *
     * @return array<int, array{0:int,1:int,2:int}>
     */
    private static function colour_ramp(): array {
        if (self::$colourramp !== null) {
            return self::$colourramp;
        }

        $stops = [[0, 0, 255], [0, 255, 255], [0, 255, 0], [255, 255, 0], [255, 0, 0]];
        $ramp = [];
        for ($i = 0; $i < 256; $i++) {
            $t = $i / 255 * (count($stops) - 1);
            $idx = min(count($stops) - 2, (int) floor($t));
            $frac = $t - $idx;
            $a = $stops[$idx];
            $b = $stops[$idx + 1];
            $ramp[$i] = [
                (int) round($a[0] + ($b[0] - $a[0]) * $frac),
                (int) round($a[1] + ($b[1] - $a[1]) * $frac),
                (int) round($a[2] + ($b[2] - $a[2]) * $frac),
            ];
        }

        self::$colourramp = $ramp;
        return $ramp;
    }
}
