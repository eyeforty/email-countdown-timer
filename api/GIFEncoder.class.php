<?php
/**
 * AnimatedGif – minimal PHP-8–compatible fork of “GIFEncoder v2.0”
 * Original author: László Zsidi – http://gifs.hu
 * PHP-8 patch: 2025-07-07
 */
class AnimatedGif
{
    /** @var string  the final GIF binary */
    private $image = '';

    /** @var array<string>  binary source frames */
    private $buffer = [];

    /** @var int  how many times to loop (0 = infinite) */
    private $number_of_loops = 0;

    /** @var int  disposal method */
    private $DIS = 2;

    /** @var int  packed RGB value of the transparent colour or –1 */
    private $transparent_colour = -1;

    /** @var bool  is this the first frame? */
    private $first_frame = true;

    /**
     * @param array<string> $source_images  raw GIF binaries
     * @param array<int>    $image_delays   delay for each frame ( 1/100 s )
     * @param int           $number_of_loops
     * @param int           $transparent_colour_red
     * @param int           $transparent_colour_green
     * @param int           $transparent_colour_blue
     * @throws \Exception
     */
    public function __construct(
        array $source_images,
        array $image_delays,
        int   $number_of_loops,
        int   $transparent_colour_red   = -1,
        int   $transparent_colour_green = -1,
        int   $transparent_colour_blue  = -1
    ) {
        // (The transparent-colour parameters were unused in the 2007 code.
        //  Keeping them for API compatibility.)
        $transparent_colour_red   = 0;
        $transparent_colour_green = 0;
        $transparent_colour_blue  = 0;

        $this->number_of_loops = max(0, $number_of_loops);
        $this->set_transparent_colour(
            $transparent_colour_red,
            $transparent_colour_green,
            $transparent_colour_blue
        );

        $this->buffer_images($source_images);

        $this->addHeader();
        for ($i = 0; $i < count($this->buffer); $i++) {
            $this->addFrame($i, $image_delays[$i]);
        }
        $this->addFooter(); // append the terminator once construction is done
    }

    /** Set the transparent colour */
    private function set_transparent_colour(int $r, int $g, int $b): void
    {
        $this->transparent_colour =
            ($r > -1 && $g > -1 && $b > -1) ? ($r | ($g << 8) | ($b << 16)) : -1;
    }

    /** Validate and stash the source frames */
    private function buffer_images(array $source_images): void
    {
        foreach ($source_images as $i => $gif) {
            $this->buffer[] = $gif;
            if (!in_array(substr($gif, 0, 6), ['GIF87a', 'GIF89a'], true)) {
                throw new \Exception("Image #$i is not a GIF.");
            }

            // Reject already-animated GIFs (they contain a NETSCAPE extension)
            for (
                $j = 13 + 3 * (2 << (ord($gif[10]) & 0x07)), $keep_scanning = true;
                $keep_scanning;
                $j++
            ) {
                switch ($gif[$j]) {
                    case '!':
                        if (substr($gif, $j + 3, 8) === 'NETSCAPE') {
                            throw new \Exception(
                                'Cannot build an animation from an already animated GIF.'
                            );
                        }
                        break;
                    case ';':
                        $keep_scanning = false;
                        break;
                }
            }
        }
    }

    /** Write the logical-screen descriptor and global colour table (frame 0) */
    private function addHeader(): void
    {
        $this->image = 'GIF89a';
        if (ord($this->buffer[0][10]) & 0x80) {                           // GCT present?
            $cmap = 3 * (2 << (ord($this->buffer[0][10]) & 0x07));        // gct size
            $this->image .= substr($this->buffer[0], 6, 7);               // LSD
            $this->image .= substr($this->buffer[0], 13, $cmap);          // GCT
            // Netscape application-extension – repeat X times
            $this->image .= "!\xFF\x0BNETSCAPE2.0\x03\x01"
                . $this->word($this->number_of_loops) . "\0";
        }
    }

    /** Insert one frame and its graphics-control extension */
    private function addFrame(int $frame, int $delay): void
    {
        // Where does the image descriptor of this frame start?
        $locals_str = 13 + 3 * (2 << (ord($this->buffer[$frame][10]) & 0x07));

        $locals_end = strlen($this->buffer[$frame]) - $locals_str - 1;
        $locals_tmp = substr($this->buffer[$frame], $locals_str, $locals_end);

        $global_len  = 2 << (ord($this->buffer[0][10]) & 0x07);
        $locals_len  = 2 << (ord($this->buffer[$frame][10]) & 0x07);

        $global_rgb  = substr($this->buffer[0],        13, 3 * $global_len);
        $locals_rgb  = substr($this->buffer[$frame],   13, 3 * $locals_len);

        $locals_ext  = "!\xF9\x04" . chr(($this->DIS << 2))
            . chr($delay & 0xFF) . chr(($delay >> 8) & 0xFF) . "\x0\x0";

        /* Transparency sniff */
        if (
            $this->transparent_colour > -1
            && (ord($this->buffer[$frame][10]) & 0x80)
        ) {
            for ($j = 0; $j < $locals_len; $j++) {
                if (
                    ord($locals_rgb[3 * $j + 0]) === (($this->transparent_colour >> 16) & 0xFF)
                    && ord($locals_rgb[3 * $j + 1]) === (($this->transparent_colour >> 8) & 0xFF)
                    && ord($locals_rgb[3 * $j + 2]) === (($this->transparent_colour) & 0xFF)
                ) {
                    $locals_ext = "!\xF9\x04" . chr(($this->DIS << 2) + 1)
                        . chr($delay & 0xFF) . chr(($delay >> 8) & 0xFF)
                        . chr($j) . "\x0";
                    break;
                }
            }
        }

        /* Extract the image descriptor + lzw data */
        switch ($locals_tmp[0]) {
            case '!': // graphic-control block in source
                $locals_img = substr($locals_tmp, 8, 10);
                $locals_tmp = substr($locals_tmp, 18);
                break;
            case ',': // image-descriptor starts immediately
                $locals_img = substr($locals_tmp, 0, 10);
                $locals_tmp = substr($locals_tmp, 10);
                break;
            default:
                $locals_img = '';
        }

        /* Decide whether to re-emit a colour table */
        if (
            (ord($this->buffer[$frame][10]) & 0x80)   // local colour table present
            && $this->first_frame === false
        ) {
            if ($global_len === $locals_len && $this->blockCompare($global_rgb, $locals_rgb, $global_len)) {
                // identical to global table – omit it
                $this->image .= $locals_ext . $locals_img . $locals_tmp;
            } else {
                // different size or colours – include the LCT
                $byte = ord($locals_img[9]);
                $byte |= 0x80;         // set “LCT present”
                $byte &= 0xF8;         // clear size bits
                $byte |= (ord($this->buffer[0][10]) & 0x07); // size of GCT
                $locals_img[9] = chr($byte);
                $this->image .= $locals_ext . $locals_img . $locals_rgb . $locals_tmp;
            }
        } else {
            // reuse global colour table
            $this->image .= $locals_ext . $locals_img . $locals_tmp;
        }

        $this->first_frame = false;
    }

    /** GIF trailer */
    private function addFooter(): void
    {
        $this->image .= ';';
    }

    /** Compare two colour-tables byte-for-byte */
    private function blockCompare(string $g, string $l, int $len): bool
    {
        for ($i = 0; $i < $len; $i++) {
            if (
                $g[3 * $i + 0] !== $l[3 * $i + 0] ||
                $g[3 * $i + 1] !== $l[3 * $i + 1] ||
                $g[3 * $i + 2] !== $l[3 * $i + 2]
            ) {
                return false;
            }
        }
        return true;
    }

    /** Convert an int to little-endian 16-bit word */
    private function word(int $v): string
    {
        return chr($v & 0xFF) . chr(($v >> 8) & 0xFF);
    }

    /** Get the finished animation binary */
    public function getAnimation(): string
    {
        return $this->image;
    }

    /** Output directly with the proper header */
    public function display(): void
    {
        header('Content-Type: image/gif');
        echo $this->image;
    }
}
