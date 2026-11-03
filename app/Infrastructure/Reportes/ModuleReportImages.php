<?php

namespace App\Infrastructure\Reportes;

class ModuleReportImages
{
    public function render(array $report): array
    {
        $images = [];
        $colors = [[6, 182, 212], [139, 92, 246], [234, 179, 8], [16, 185, 129], [244, 63, 94]];
        foreach (['trend', 'distribution'] as $type) {
            $im = imagecreatetruecolor(1000, 420);
            imagefill($im, 0, 0, imagecolorallocate($im, 255, 255, 255));
            $ink = imagecolorallocate($im, 30, 41, 59);
            $font = base_path('vendor/dompdf/dompdf/lib/fonts/DejaVuSans.ttf');
            $text = function (int $x, int $y, string $value, int $color) use ($im, $font): void {
                imagettftext($im, 11, 0, $x, $y, $color, $font, $value);
            };
            $chart = $report['charts'][$type];
            $text(20, 25, $type === 'trend' ? 'Evolución diaria · Bs (resultado conocido, parcial si hay pendientes)' : 'Distribución de cobros · Bs', $ink);
            if ($type === 'trend') {
                $values = array_merge(...array_column($chart['datasets'], 'data'));
                $min = min(0, ...$values);
                $max = max(1, ...$values);
                for ($i = 0; $i <= 4; $i++) {
                    $y = 55 + $i * 65;
                    imageline($im, 90, $y, 970, $y, imagecolorallocate($im, 226, 232, 240));
                    $text(5, $y + 4, number_format($max - ($max - $min) * $i / 4, 2, '.', ''), $ink);
                }
                foreach ($chart['datasets'] as $j => $series) {
                    $c = imagecolorallocate($im, ...$colors[$j]);
                    $last = null;
                    foreach ($series['data'] as $i => $value) {
                        $x = (int) (90 + 880 * $i / max(1, count($series['data']) - 1));
                        $y = (int) (55 + 260 * ($max - $value) / ($max - $min));
                        if ($last) {
                            imagesetthickness($im, 3);
                            imageline($im, $last[0], $last[1], $x, $y, $c);
                        }
                        imagefilledellipse($im, $x, $y, 5, 5, $c);
                        $last = [$x, $y];
                        if ($j === 0 && ($i % 5 === 0 || $i === count($series['data']) - 1)) {
                            $text($x - 15, 340, $chart['labels'][$i], $ink);
                        }
                    }
                    $text(100 + $j * 390, 385, $series['label'], $c);
                }
            } else {
                $values = $chart['datasets'][0]['data'];
                $sum = array_sum($values);
                $angle = 0;
                if ($sum <= 0) {
                    $text(100, 200, 'Sin cobros registrados en este mes', $ink);
                }
                foreach ($values as $i => $value) {
                    $c = imagecolorallocate($im, ...$colors[$i % count($colors)]);
                    if ($value > 0 && $sum > 0) {
                        $end = $angle + 360 * $value / $sum;
                        imagefilledarc($im, 240, 220, 310, 310, (int) $angle, (int) $end, $c, IMG_ARC_PIE);
                        $angle = $end;
                    }
                    $text(450, 85 + 32 * $i, $chart['labels'][$i].': '.number_format($value, 2, '.', '').' Bs', $c);
                }
                if ($sum > 0) {
                    imagefilledellipse($im, 240, 220, 150, 150, imagecolorallocate($im, 255, 255, 255));
                }
            }
            ob_start();
            imagepng($im);
            $images[$type] = ob_get_clean();
            imagedestroy($im);
        }

        return $images;
    }
}
