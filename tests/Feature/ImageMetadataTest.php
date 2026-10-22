<?php

namespace Aurora\Tests\Feature;

use Aurora\Filament\Resources\Assets\Pages\EditAsset;
use Aurora\Models\Asset;
use Aurora\Models\User;
use Aurora\Support\AssetLibrary;
use Aurora\Support\AudioMetadata;
use Aurora\Support\ImageMetadata;
use Aurora\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

class ImageMetadataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    public function test_a_photos_exif_data_is_shown_in_plain_words(): void
    {
        $photo = AssetLibrary::fromUpload($this->jpegWithExif());

        $summary = array_map(fn (array $item): string => $item['value'], ImageMetadata::read($photo)['summary']);

        $this->assertSame('Fujifilm X-T5', $summary['Camera']);
        $this->assertSame('1/250 s', $summary['Exposure']);
        $this->assertSame('ƒ/2.8', $summary['Aperture']);
        $this->assertSame('400', $summary['ISO']);
        $this->assertSame('35 mm', $summary['Focal length']);
        $this->assertSame('59.90000, 10.75000', $summary['Location']);
        $this->assertArrayHasKey('Taken', $summary);

        Livewire::actingAs($this->userWithRole(User::SUPER_ADMIN))
            ->test(EditAsset::class, ['record' => $photo->getKey()])
            ->assertSee('EXIF data')
            ->assertSee('Fujifilm X-T5');
    }

    public function test_the_section_shows_only_for_formats_that_carry_exif_and_files_that_have_some(): void
    {
        $png = AssetLibrary::fromUpload(UploadedFile::fake()->image('logo.png', 40, 40));
        $plainJpeg = AssetLibrary::fromUpload(UploadedFile::fake()->image('plain.jpg', 40, 40));

        $this->assertFalse(ImageMetadata::supports($png));
        $this->assertTrue(ImageMetadata::supports($plainJpeg));

        Livewire::actingAs($this->userWithRole(User::SUPER_ADMIN))
            ->test(EditAsset::class, ['record' => $png->getKey()])
            ->assertDontSee('EXIF data');

        Livewire::actingAs($this->userWithRole(User::SUPER_ADMIN))
            ->test(EditAsset::class, ['record' => $plainJpeg->getKey()])
            ->assertDontSee('EXIF data', 'A JPEG without any: no section.');
    }

    /**
     * A small JPEG with an EXIF block: camera, exposure, date taken and where.
     */
    protected function jpegWithExif(): UploadedFile
    {
        $jpeg = UploadedFile::fake()->image('photo.jpg', 64, 48)->getContent();

        $ascii = fn (string $text): array => ['type' => 2, 'count' => strlen($text) + 1, 'data' => $text."\0"];
        $rationals = fn (array $values): array => ['type' => 5, 'count' => count($values), 'data' => implode('', array_map(fn (array $value): string => pack('VV', ...$value), $values))];

        // Little-endian TIFF: the header, then IFD0, the Exif IFD and the GPS IFD, each followed by its data.
        $ifd = function (array $entries, int $offset, array $pointers = []) use (&$ifd): string {
            ksort($entries);
            $dataOffset = $offset + 2 + count($entries) * 12 + 4;
            $table = pack('v', count($entries));
            $data = '';

            foreach ($entries as $tag => $entry) {
                $value = $entry['data'];

                if (strlen($value) <= 4) {
                    $table .= pack('vvV', $tag, $entry['type'], $entry['count']).str_pad($value, 4, "\0");
                } else {
                    $table .= pack('vvVV', $tag, $entry['type'], $entry['count'], $dataOffset + strlen($data));
                    $data .= $value.(strlen($value) % 2 ? "\0" : '');
                }
            }

            return $table.pack('V', 0).$data;
        };

        $exifEntries = [
            0x829A => $rationals([[1, 250]]),
            0x829D => $rationals([[28, 10]]),
            0x8827 => ['type' => 3, 'count' => 1, 'data' => pack('v', 400)],
            0x9003 => $ascii('2026:06:01 12:30:00'),
            0x920A => $rationals([[35, 1]]),
        ];
        $gpsEntries = [
            0x0001 => $ascii('N'),
            0x0002 => $rationals([[59, 1], [54, 1], [0, 1]]),
            0x0003 => $ascii('E'),
            0x0004 => $rationals([[10, 1], [45, 1], [0, 1]]),
        ];

        // IFD0 first: its size doesn't depend on where the other two land, only its pointers do.
        $ifd0Entries = fn (int $exifAt, int $gpsAt): array => [
            0x010F => $ascii('Fujifilm'),
            0x0110 => $ascii('X-T5'),
            0x8769 => ['type' => 4, 'count' => 1, 'data' => pack('V', $exifAt)],
            0x8825 => ['type' => 4, 'count' => 1, 'data' => pack('V', $gpsAt)],
        ];
        $ifd0Length = strlen($ifd($ifd0Entries(0, 0), 8));
        $exifAt = 8 + $ifd0Length;
        $exifIfd = $ifd($exifEntries, $exifAt);
        $gpsAt = $exifAt + strlen($exifIfd);

        $tiff = 'II'.pack('vV', 42, 8).$ifd($ifd0Entries($exifAt, $gpsAt), 8).$exifIfd.$ifd($gpsEntries, $gpsAt);
        $app1 = "\xFF\xE1".pack('n', strlen($tiff) + 8)."Exif\0\0".$tiff;

        $path = sys_get_temp_dir().'/exif-'.uniqid().'.jpg';
        file_put_contents($path, substr($jpeg, 0, 2).$app1.substr($jpeg, 2));

        return new UploadedFile($path, 'photo.jpg', 'image/jpeg', null, true);
    }

    public function test_an_mp3s_id3_tags_are_shown(): void
    {
        $frame = fn (string $id, string $content): string => $id.pack('N', strlen($content))."\0\0".$content;
        $tags = $frame('TIT2', "\3Says")
            .$frame('TPE1', "\1".mb_convert_encoding("\u{FEFF}Nils Frahm", 'UTF-16LE', 'UTF-8'))
            .$frame('TALB', "\0Spaces")
            .$frame('TRCK', "\0".'3')
            // Cover art: binary, not shown.
            .$frame('APIC', "\0image/png\0\3\0".UploadedFile::fake()->image('cover.png', 4, 4)->getContent());
        $synchsafe = fn (int $size): string => implode('', array_map(fn (int $shift): string => chr(($size >> $shift) & 0x7F), [21, 14, 7, 0]));
        $song = $this->mp3("ID3\3\0\0".$synchsafe(strlen($tags)).$tags);

        $summary = array_map(fn (array $item): string => $item['value'], AudioMetadata::read($song)['summary']);

        $this->assertSame(['Title' => 'Says', 'Artist' => 'Nils Frahm', 'Album' => 'Spaces', 'Track' => '3'], array_intersect_key($summary, array_flip(['Title', 'Artist', 'Album', 'Track'])));

        Livewire::actingAs($this->userWithRole(User::SUPER_ADMIN))
            ->test(EditAsset::class, ['record' => $song->getKey()])
            ->assertSee('ID3 tags')
            ->assertSee('Nils Frahm')
            ->assertDontSee('EXIF data');
    }

    public function test_older_id3v1_tags_at_the_end_of_the_file_are_read_too(): void
    {
        $field = fn (string $text, int $length): string => str_pad($text, $length, "\0");
        $song = $this->mp3('', 'TAG'.$field('Hold On', 30).$field('Sault', 30).$field('Untitled', 30).'2020'.$field('', 28)."\0\x05\x0C");

        $summary = array_map(fn (array $item): string => $item['value'], AudioMetadata::read($song)['summary']);

        $this->assertSame(['Title' => 'Hold On', 'Artist' => 'Sault', 'Album' => 'Untitled', 'Year' => '2020', 'Track' => '5'], array_intersect_key($summary, array_flip(['Title', 'Artist', 'Album', 'Year', 'Track'])));
    }

    /**
     * An MP3 in the library: the tags around a few silent MPEG frames.
     */
    protected function mp3(string $before, string $after = ''): Asset
    {
        $frames = str_repeat("\xFF\xFB\x90\x64".str_repeat("\0", 413), 20);
        $path = sys_get_temp_dir().'/id3-'.uniqid().'.mp3';
        file_put_contents($path, $before.$frames.$after);

        return AssetLibrary::fromUpload(new UploadedFile($path, 'song.mp3', 'audio/mpeg', null, true));
    }
}
