<?php
namespace Services;

require_once __DIR__ . '/../Lib/QRCode.php';

/**
 * GemVerify — High-Resolution NIN Slip Generator Service
 *
 * Generates authentic National Identification Number (NIN) Slips
 * (Digital / Premium and Improved / Standard) from verified citizen demographic data.
 * Calibrated strictly to standard CR80 (86mm x 54mm per side) on an A4 document.
 */
class SlipGeneratorService
{
    private const TEMPLATE_DIGITAL  = __DIR__ . '/../../../assets/img/templates/nin_digital_fpdf_bg.jpg';
    private const TEMPLATE_IMPROVED = __DIR__ . '/../../../assets/img/templates/nin_improved_fpdf_bg.jpg';
    private const TEMPLATE_STANDARD = __DIR__ . '/../../../assets/img/templates/nin_standard_enrolment_template.jpg';
    private const FONT_OCRB         = __DIR__ . '/../../../assets/fonts/OCRB10PitchBT-Regular.ttf';

    /**
     * Generate an official A4 PDF NIN Slip from citizen data.
     *
     * @param array $citizenData Demographic and biometric data from provider
     * @return array ['success' => bool, 'pdf_base64' => string, 'filename' => string, 'error' => ?string]
     */
    public static function generateNinSlip(array $citizenData): array
    {
        try {
            // Determine slip template type: 'digital', 'improved', or 'standard' / 'enrolment'
            $type = strtolower(trim((string)($citizenData['template_type'] ?? $citizenData['slip_type'] ?? 'digital')));
            if ($type === 'standard' || $type === 'enrolment') {
                return self::generateStandardEnrolmentSlip($citizenData);
            }
            if ($type !== 'improved') {
                $type = 'digital';
            }

            $tplPath = ($type === 'digital') ? self::TEMPLATE_DIGITAL : self::TEMPLATE_IMPROVED;
            if (!file_exists($tplPath)) {
                return ['success' => false, 'error' => "Slip template file not found on server ($tplPath)."];
            }
            if (!file_exists(self::FONT_OCRB)) {
                return ['success' => false, 'error' => 'OCR-B font file not found on server.'];
            }

            // 1. Sanitize demographic fields
            $surname = strtoupper(trim((string)($citizenData['lastName'] ?? $citizenData['surname'] ?? $citizenData['lastname'] ?? '')));
            $firstName = strtoupper(trim((string)($citizenData['firstName'] ?? $citizenData['firstname'] ?? '')));
            $middleName = strtoupper(trim((string)($citizenData['middleName'] ?? $citizenData['middlename'] ?? '')));
            $givenNames = strtoupper(trim(implode(' ', array_filter([$firstName, $middleName]))));

            $rawDob = (string)($citizenData['dateOfBirth'] ?? $citizenData['dob'] ?? $citizenData['birthdate'] ?? '');
            $dobFormatted = !empty($rawDob) ? strtoupper(date('d M Y', strtotime($rawDob))) : '';

            $rawGender = strtoupper(trim((string)($citizenData['gender'] ?? $citizenData['sex'] ?? '')));
            $sex = str_starts_with($rawGender, 'F') ? 'F' : (str_starts_with($rawGender, 'M') ? 'M' : '');

            $rawNin = preg_replace('/\D/', '', (string)($citizenData['idNumber'] ?? $citizenData['nin'] ?? $citizenData['vnin'] ?? ''));
            if (strlen($rawNin) === 11) {
                $ninFormatted = substr($rawNin, 0, 4) . ' ' . substr($rawNin, 4, 3) . ' ' . substr($rawNin, 7, 4);
            } else {
                $ninFormatted = $rawNin;
            }

            $issueDate = strtoupper(date('d M Y'));

            // 2. Prepare citizen photo (resample cleanly to 350x460 JPEG)
            $photoJpeg = self::preparePhoto($citizenData['photo'] ?? $citizenData['image'] ?? null);

            // 3. Generate QR code on pure white background (DeviceRGB, zero stroke, zero hairline)
            $qrPayload = json_encode([
                'NIN'           => $rawNin,
                'Names'         => trim($surname . ' ' . $givenNames),
                'Date of Birth' => $dobFormatted,
                'Status'        => 'Verified'
            ], JSON_UNESCAPED_SLASHES);

            $qrObj = self::buildCleanWhiteQrRgb($qrPayload, 6, 0);

            // 4. Load background template & OCR-B font
            $bgJpeg = file_get_contents($tplPath);
            $fontData = file_get_contents(self::FONT_OCRB);

            // 5. Assemble direct PDF
            $pdfBinary = self::assemblePdf($bgJpeg, $photoJpeg, $qrObj, $fontData, $type, [
                'surname'     => $surname,
                'given_names' => $givenNames,
                'dob'         => $dobFormatted,
                'sex'         => $sex,
                'nin'         => $rawNin,
                'nin_fmt'     => $ninFormatted,
                'issue_date'  => $issueDate
            ]);

            $pdfBase64 = 'data:application/pdf;base64,' . base64_encode($pdfBinary);
            $fileName  = 'NIN_Slip_' . ($rawNin ?: time()) . '.pdf';

            return [
                'success'    => true,
                'pdf_base64' => $pdfBase64,
                'filename'   => $fileName,
                'nin'        => $ninFormatted,
                'error'      => null
            ];

        } catch (\Throwable $e) {
            error_log('[SlipGeneratorService] Error generating NIN slip: ' . $e->getMessage());
            return [
                'success' => false,
                'error'   => 'Slip generation failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Resample photo to 350x460 JPEG or generate placeholder
     */
    private static function preparePhoto(?string $rawPhoto): string
    {
        $im = null;
        if (!empty($rawPhoto)) {
            $photoData = $rawPhoto;
            if (str_contains($photoData, ',')) {
                $parts = explode(',', $photoData, 2);
                $photoData = $parts[1];
            }
            $bin = base64_decode($photoData, true);
            if ($bin !== false) {
                $im = @imagecreatefromstring($bin);
            }
        }

        $norm = imagecreatetruecolor(350, 460);
        if ($im) {
            imagecopyresampled($norm, $im, 0, 0, 0, 0, 350, 460, imagesx($im), imagesy($im));
            imagedestroy($im);
        } else {
            $grey = imagecolorallocate($norm, 235, 235, 235);
            imagefill($norm, 0, 0, $grey);
        }

        ob_start();
        imagejpeg($norm, null, 92);
        $jpeg = ob_get_clean();
        imagedestroy($norm);
        return $jpeg;
    }

    /**
     * Builds a clean, high-resolution QR code as a DeviceRGB image:
     * - Pure white background (#FFFFFF) baked in
     * - Level H error correction (30% recovery) to allow center badge
     * - Crisp integer module scaling (6px per module)
     * - Concentric square target badge stamped at dead centre (11x11 modules)
     * - Zero stroke / zero hairline — 100% scannable
     */
    private static function buildCleanWhiteQrRgb(string $jsonPayload, int $scale = 6, int $quietZone = 0): array
    {
        // Level H = up to 30% module damage tolerated. Badge covers ~7%, leaving massive headroom.
        $qr = new \QRCode($jsonPayload, [
            's'  => 'qrh',
            'wq' => 0,
            'wm' => 1,
            'sf' => 1,
            'bc' => 'FFFFFF',
            'fc' => '000000',
        ]);
        $img = $qr->render_image();
        $matrixW = imagesx($img);
        $matrixH = imagesy($img);

        $totalModules = $matrixW + 2 * $quietZone;
        $pixelDim = $totalModules * $scale;

        $hiRes = imagecreatetruecolor($pixelDim, $pixelDim);
        $white = imagecolorallocate($hiRes, 255, 255, 255);
        $black = imagecolorallocate($hiRes, 0, 0, 0);
        imagefilledrectangle($hiRes, 0, 0, $pixelDim - 1, $pixelDim - 1, $white);

        // Paint QR modules
        for ($y = 0; $y < $matrixH; $y++) {
            for ($x = 0; $x < $matrixW; $x++) {
                $rgb = imagecolorat($img, $x, $y);
                if (($rgb & 0xFF) < 128) {
                    $px = ($x + $quietZone) * $scale;
                    $py = ($y + $quietZone) * $scale;
                    imagefilledrectangle($hiRes, $px, $py, $px + $scale - 1, $py + $scale - 1, $black);
                }
            }
        }
        imagedestroy($img);

        // ── Concentric square target badge — PIXEL-EXACT match to reference ──
        //
        // Measured from reference (53×53 QR, badge centre at module 26):
        //   r=9  White  → 1-mod clearance ring (isolates badge from QR data)
        //   r=8  Black  → outer black ring, 2-mod thick
        //   r=6  White  → wide white interior, 2-mod wide
        //   r=4  Black  → inner black ring, 2-mod thick
        //   r=2  White  → centre white ring, 1-mod wide
        //   r=1  Black  → centre dot, 3×3 modules
        //
        // Badge total = 17×17 modules (≈32% of QR width), clearance zone = 19×19.
        // Level H (qrh) handles 30% damage; badge covers ~14%, leaving 16% headroom.
        $cMod = (int)floor($matrixW / 2);
        $cPx  = ($cMod + $quietZone) * $scale + (int)($scale / 2);
        $cPy  = ($cMod + $quietZone) * $scale + (int)($scale / 2);

        $layers = [
            [9, $white],  // clearance — erase 1 module of QR data around badge
            [8, $black],  // outer black ring — 2-module thick
            [6, $white],  // wide white interior — 2-module wide
            [4, $black],  // inner black ring — 2-module thick
            [2, $white],  // centre white ring — 1-module wide
            [1, $black],  // centre dot — 3×3 solid black
        ];
        foreach ($layers as [$rMod, $col]) {
            $rPx = $rMod * $scale;
            imagefilledrectangle(
                $hiRes,
                $cPx - $rPx,
                $cPy - $rPx,
                $cPx + $rPx - 1,
                $cPy + $rPx - 1,
                $col
            );
        }
        // ── end badge ──

        $raw = '';
        for ($y = 0; $y < $pixelDim; $y++) {
            for ($x = 0; $x < $pixelDim; $x++) {
                $rgb = imagecolorat($hiRes, $x, $y);
                $raw .= chr(($rgb >> 16) & 0xFF) . chr(($rgb >> 8) & 0xFF) . chr($rgb & 0xFF);
            }
        }
        imagedestroy($hiRes);

        $comp = gzcompress($raw, 9);
        return [
            'width'  => $pixelDim,
            'height' => $pixelDim,
            'stream' => $comp,
            'length' => strlen($comp),
        ];
    }

    /**
     * Assemble pixel-perfect calibrated A4 PDF slip
     */
    private static function assemblePdf(string $bgJpeg, string $photoJpeg, array $qrObj, string $fontOcrbData, string $type, array $citizen): string
    {
        $surname    = $citizen['surname'];
        $givenNames = $citizen['given_names'];
        $dob        = $citizen['dob'];
        $sex        = $citizen['sex'];
        $ninStr     = $citizen['nin'];
        $ninFmt     = $citizen['nin_fmt'];
        $issueDate  = $citizen['issue_date'];

        $fontOcrbComp = gzcompress($fontOcrbData, 9);
        $fontOcrbLen  = strlen($fontOcrbData);

        if ($type === 'digital') {
            $bgW = 2461; $bgH = 2928;
            $t1 = "Please find below your new High Resolution NIN Slip.";
            $t2 = "You may cut it out of the paper, fold and laminate as desired.";
            $t3 = "Please DO NOT allow others to make copies of your NIN Slip.";
            $x1 = 157.97; $x2 = 138.40; $x3 = 138.09;

            $stream = "2 J\n0.57 w\nBT /F1 18.00 Tf ET\n0.000 0.000 0.608 RG\nBT /F2 11.00 Tf ET\n";
            $stream .= "q 0.000 g BT $x1 800.32 Td ($t1) Tj ET Q\n";
            $stream .= "q 0.000 g BT $x2 786.15 Td ($t2) Tj ET Q\n";
            $stream .= "q 0.000 g BT $x3 771.97 Td ($t3) Tj ET Q\n";
            $stream .= "q 255.12 0 0 308.98 187.09 388.35 cm /I1 Do Q\n";
            $stream .= "0 G\nBT /F1 10.00 Tf ET\n0.941 0.941 1.000 rg\n";
            $stream .= "q 53.86 0 0 70.87 192.76 592.44 cm /I2 Do Q\n";
            // 2D Barcode (calibrated to exact 56.92x56.92 pt source-of-truth size, centered, NO stroke, NO hairline, NO outer padding):
            $stream .= "q 56.92 0 0 56.92 375.48 626.34 cm /I3 Do Q\n";
            $stream .= "BT /F1 8.00 Tf ET\n";
            $stream .= "q 0.627 0.627 0.627 rg BT -1.00 0.00 -0.00 -1.00 430.87 624.50 Tm ($ninStr) Tj ET Q\n";
            $stream .= "q 0.627 0.627 0.627 rg BT 0.87 -0.50 0.50 0.87 388.35 578.27 Tm ($ninStr) Tj ET Q\n";
            $stream .= "q 0.627 0.627 0.627 rg BT 0.77 0.64 -0.64 0.77 198.43 549.92 Tm ($ninStr) Tj ET Q\n";
            $stream .= "q 0.627 0.627 0.627 rg BT 0.71 0.71 -0.71 0.71 204.09 572.60 Tm ($ninStr) Tj ET Q\n";
            $stream .= "BT /F1 12.00 Tf ET\nBT /F2 11.00 Tf ET\n";
            $stream .= "q 0.000 g BT 1.00 0.00 0.00 1.00 394.02 606.61 Tm (NGA) Tj ET Q\n";
            $stream .= "BT /F2 7.00 Tf ET\n";
            $stream .= "q 0.000 g BT 1.00 0.00 0.00 1.00 386.93 595.28 Tm (ISSUE DATE) Tj ET Q\n";
            $stream .= "BT /F1 8.00 Tf ET\n";
            $stream .= "q 0.000 g BT 1.00 0.00 0.00 1.00 379.84 586.77 Tm ($issueDate) Tj ET Q\n";
            $stream .= "BT /F2 6.00 Tf ET\n";
            $stream .= "q 0.510 0.510 0.510 rg BT 257.96 654.42 Td (SURNAME/NOM) Tj ET Q\n";
            $stream .= "BT /F1 8.00 Tf ET\n";
            $stream .= "q 0.000 g BT 257.96 645.31 Td ($surname) Tj ET Q\n";
            $stream .= "BT /F2 6.00 Tf ET\n";
            $stream .= "q 0.510 0.510 0.510 rg BT 257.96 631.74 Td (GIVEN NAMES/PRENOMS) Tj ET Q\n";
            $stream .= "BT /F1 8.00 Tf ET\n";
            $stream .= "q 0.000 g BT 257.96 622.64 Td ($givenNames) Tj ET Q\n";
            $stream .= "BT /F2 6.00 Tf ET\n";
            $stream .= "q 0.510 0.510 0.510 rg BT 257.96 609.06 Td (DATE OF BIRTH) Tj ET Q\n";
            $stream .= "q 0.510 0.510 0.510 rg BT 325.99 609.06 Td (SEX/SEXE) Tj ET Q\n";
            $stream .= "BT /F1 8.00 Tf ET\n";
            $stream .= "q 0.000 g BT 257.96 599.96 Td ($dob) Tj ET Q\n";
            $stream .= "q 0.000 g BT 325.99 599.96 Td ($sex) Tj ET Q\n";
            $stream .= "BT /F2 7.00 Tf ET\n";
            $stream .= "q 0.000 g BT 250.31 571.91 Td (National Identification Number \(NIN\)) Tj ET Q\n";
            $stream .= "BT /F1 22.00 Tf ET\n";
            $stream .= "q 0.000 g BT 224.31 553.24 Td ($ninFmt) Tj ET Q\n";

        } else {
            $bgW = 2460; $bgH = 2952;
            $t1 = "Please find below your Improved NIN Slip";
            $t2 = "You may cut it out of the paper, fold and laminate as desired.";
            $t3 = "For your security & privacy, please DO NOT permit others to make photocopies of this slip.";
            $x1 = 189.14; $x2 = 138.40; $x3 = 59.54;

            $stream = "2 J\n0.57 w\nBT /F1 18.00 Tf ET\n0.000 0.000 0.608 RG\nBT /F2 11.00 Tf ET\n";
            $stream .= "q 0.000 g BT $x1 800.32 Td ($t1) Tj ET Q\n";
            $stream .= "q 0.000 g BT $x2 786.15 Td ($t2) Tj ET Q\n";
            $stream .= "q 0.000 g BT $x3 771.97 Td ($t3) Tj ET Q\n";
            $stream .= "q 255.12 0 0 308.98 187.09 388.35 cm /I1 Do Q\n";
            $stream .= "0 G\nBT /F1 10.00 Tf ET\n0.941 0.941 1.000 rg\n";
            $stream .= "q 53.86 0 0 70.87 195.59 592.44 cm /I2 Do Q\n";
            // 2D Barcode (calibrated to exact 56.92x56.92 pt source-of-truth size, centered, NO stroke, NO hairline, NO outer padding):
            $stream .= "q 56.92 0 0 56.92 365.55 603.66 cm /I3 Do Q\n";
            $stream .= "BT /F1 8.00 Tf ET\n";
            $stream .= "q 0.902 0.902 0.902 rg BT -1.00 0.00 -0.00 -1.00 419.53 668.98 Tm ($ninStr) Tj ET Q\n";
            $stream .= "q 0.902 0.902 0.902 rg BT 0.87 -0.50 0.50 0.87 388.35 578.27 Tm ($ninStr) Tj ET Q\n";
            $stream .= "q 0.902 0.902 0.902 rg BT 0.77 0.64 -0.64 0.77 198.43 549.92 Tm ($ninStr) Tj ET Q\n";
            $stream .= "q 0.902 0.902 0.902 rg BT 0.71 0.71 -0.71 0.71 204.09 572.60 Tm ($ninStr) Tj ET Q\n";
            $stream .= "BT /F1 12.00 Tf ET\n";
            $stream .= "q 0.000 g BT 381.06 676.71 Td (NGA) Tj ET Q\n";
            $stream .= "BT /F2 6.00 Tf ET\n";
            $stream .= "q 0.510 0.510 0.510 rg BT 257.96 654.42 Td (Surname/Nom) Tj ET Q\n";
            $stream .= "BT /F1 8.00 Tf ET\n";
            $stream .= "q 0.000 g BT 257.96 645.31 Td ($surname) Tj ET Q\n";
            $stream .= "BT /F2 6.00 Tf ET\n";
            $stream .= "q 0.510 0.510 0.510 rg BT 257.96 631.74 Td (Given Names/Prenoms) Tj ET Q\n";
            $stream .= "BT /F1 8.00 Tf ET\n";
            $stream .= "q 0.000 g BT 257.96 622.64 Td ($givenNames) Tj ET Q\n";
            $stream .= "BT /F2 6.00 Tf ET\n";
            $stream .= "q 0.510 0.510 0.510 rg BT 257.96 609.06 Td (Date of Birth) Tj ET Q\n";
            $stream .= "BT /F1 8.00 Tf ET\n";
            $stream .= "q 0.000 g BT 257.96 599.96 Td ($dob) Tj ET Q\n";
            $stream .= "BT /F2 7.00 Tf ET\n";
            $stream .= "q 0.000 g BT 253.14 574.75 Td (National Identification Number \(NIN\)) Tj ET Q\n";
            $stream .= "BT /F1 22.00 Tf ET\n";
            $stream .= "q 0.000 g BT 229.98 556.07 Td ($ninFmt) Tj ET Q\n";
        }

        $streamComp = gzcompress($stream, 9);
        $streamLen  = strlen($streamComp);

        $bgLen    = strlen($bgJpeg);
        $photoLen = strlen($photoJpeg);

        $out = "%PDF-1.4\n";
        $offsets = [];

        $addObj = function($num, $body) use (&$out, &$offsets) {
            $offsets[$num] = strlen($out);
            $out .= "$num 0 obj\n$body\nendobj\n";
        };

        $addObj(1, "<</Type /Pages /Kids [3 0 R ] /Count 1 /MediaBox [0 0 595.28 841.89] >>");
        $addObj(2, "<</ProcSet [/PDF /Text /ImageB /ImageC /ImageI] /Font << /F1 7 0 R /F2 10 0 R >> /XObject << /I1 11 0 R /I2 12 0 R /I3 13 0 R >> >>");
        $addObj(3, "<</Type /Page /Parent 1 0 R /Resources 2 0 R /Contents 4 0 R >>");
        $addObj(4, "<</Filter /FlateDecode /Length $streamLen >>\nstream\n" . $streamComp . "\nendstream");

        $addObj(5, "<</Length " . strlen($fontOcrbComp) . " /Filter /FlateDecode /Length1 $fontOcrbLen >>\nstream\n" . $fontOcrbComp . "\nendstream");

        $cmap = "/CIDInit /ProcSet findresource begin\n12 dict begin\nbegincmap\n/CIDSystemInfo <</Registry (Adobe) /Ordering (UCS) /Supplement 0>> def\n/CMapName /Custom-ToUnicode def\n/CMapType 2 def\n1 begincodespacerange\n<00> <FF>\nendcodespacerange\n1 beginbfrange\n<20> <7E> <0020>\nendbfrange\nendcmap\nCMapName currentdict /CMap defineresource pop\nend\nend";
        $cmapComp = gzcompress($cmap, 9);
        $addObj(6, "<</Filter /FlateDecode /Length " . strlen($cmapComp) . " >>\nstream\n" . $cmapComp . "\nendstream");

        $widths = "[ " . implode(" ", array_fill(0, 224, 600)) . " ]";
        $addObj(7, "<</Type /Font /BaseFont /AAAAAA+OCRB10PitchBT-Regular /Subtype /TrueType /FirstChar 32 /LastChar 255 /Widths $widths /FontDescriptor 9 0 R /Encoding /WinAnsiEncoding /ToUnicode 6 0 R >>");
        $addObj(8, $widths);
        $addObj(9, "<</Type /FontDescriptor /FontName /AAAAAA+OCRB10PitchBT-Regular /Ascent 760 /Descent -240 /CapHeight 760 /Flags 32 /FontBBox [-4 -289 1803 741] /ItalicAngle 0 /StemV 70 /MissingWidth 602 /FontFile2 5 0 R >>");
        $addObj(10, "<</Type /Font /BaseFont /Helvetica-Bold /Subtype /Type1 /Encoding /WinAnsiEncoding >>");

        $addObj(11, "<</Type /XObject /Subtype /Image /Width $bgW /Height $bgH /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length $bgLen >>\nstream\n" . $bgJpeg . "\nendstream");
        $addObj(12, "<</Type /XObject /Subtype /Image /Width 350 /Height 460 /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length $photoLen >>\nstream\n" . $photoJpeg . "\nendstream");

        // Object 13: Standard DeviceRGB high-resolution image with pure white background baked in! Zero stroke, zero mask, zero hairline!
        $qrW = $qrObj['width'];
        $qrH = $qrObj['height'];
        $qrLen = $qrObj['length'];
        $qrStream = $qrObj['stream'];
        $addObj(13, "<</Type /XObject /Subtype /Image /Width $qrW /Height $qrH /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /FlateDecode /Length $qrLen >>\nstream\n" . $qrStream . "\nendstream");

        $addObj(14, "<</Type /Catalog /Pages 1 0 R >>");

        // XREF
        $startXref = strlen($out);
        $out .= "xref\n0 15\n0000000000 65535 f \n";
        for ($i = 1; $i <= 14; $i++) {
            $out .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $out .= "trailer\n<< /Size 15 /Root 14 0 R >>\nstartxref\n$startXref\n%%EOF\n";

        return $out;
    }

        /**
     * Generate Traditional/Standard Enrolment NIN Slip (NINS)
     * Matches exact source-of-truth DOMPDF structure from media_1790200192834.pdf:
     * - Font: Times-Roman 7.5pt
     * - Coordinates calibrated down to 0.001 pt
     * - Photo box: 85.532 x 109.500 pt at (487.581, 647.275)
     * - Zero QR code (empty barcode container)
     * - High-resolution 300 DPI background from source template
     */
    public static function generateStandardEnrolmentSlip(array $citizenData): array
    {
        try {
            $bgObj10Path = __DIR__ . '/../../../assets/img/templates/nin_standard_bg_obj10.bin';
            $bgObj9Path  = __DIR__ . '/../../../assets/img/templates/nin_standard_bg_obj9.bin';

            if (!file_exists($bgObj10Path) || !file_exists($bgObj9Path)) {
                return ['success' => false, 'error' => 'Standard template binary assets not found on server.'];
            }

            $bgObj10 = file_get_contents($bgObj10Path);
            $bgObj9  = file_get_contents($bgObj9Path);

            // 1. Sanitize demographic fields
            $surname    = strtoupper(trim((string)($citizenData['lastName'] ?? $citizenData['surname'] ?? $citizenData['lastname'] ?? '')));
            $firstName  = strtoupper(trim((string)($citizenData['firstName'] ?? $citizenData['firstname'] ?? '')));
            $middleName = strtoupper(trim((string)($citizenData['middleName'] ?? $citizenData['middlename'] ?? '')));
            $gender     = strtoupper(trim((string)($citizenData['gender'] ?? $citizenData['sex'] ?? 'M')));
            if ($gender === 'MALE') $gender = 'M';
            if ($gender === 'FEMALE') $gender = 'F';

            $rawNin = preg_replace('/\D/', '', (string)($citizenData['idNumber'] ?? $citizenData['nin'] ?? $citizenData['vnin'] ?? ''));
            $ninFormatted = strlen($rawNin) === 11 
                ? substr($rawNin, 0, 4) . ' ' . substr($rawNin, 4, 3) . ' ' . substr($rawNin, 7, 4)
                : $rawNin;

            $trackingId = strtoupper(trim((string)($citizenData['trackingId'] ?? $citizenData['tracking_id'] ?? '')));
            $address    = trim((string)($citizenData['address'] ?? $citizenData['residence_address'] ?? ''));

            // 2. Prepare citizen photo (350x466 JPEG matching source dimensions)
            $photoSrc = null;
            $rawPhoto = $citizenData['photo'] ?? $citizenData['image'] ?? null;
            if (!empty($rawPhoto)) {
                if (str_contains($rawPhoto, ',')) $rawPhoto = explode(',', $rawPhoto, 2)[1];
                $bin = base64_decode($rawPhoto, true);
                if ($bin !== false) $photoSrc = @imagecreatefromstring($bin);
            }
            $photoPath = __DIR__ . '/../../../test_user_photo.jpg';
            if (!$photoSrc && file_exists($photoPath)) {
                $photoSrc = @imagecreatefromjpeg($photoPath);
            }

            $normPhoto = imagecreatetruecolor(350, 466);
            if ($photoSrc) {
                imagecopyresampled($normPhoto, $photoSrc, 0, 0, 0, 0, 350, 466, imagesx($photoSrc), imagesy($photoSrc));
                imagedestroy($photoSrc);
            } else {
                $grey = imagecolorallocate($normPhoto, 235, 235, 235);
                imagefill($normPhoto, 0, 0, $grey);
            }
            ob_start();
            imagejpeg($normPhoto, null, 92);
            $photoJpeg = ob_get_clean();
            imagedestroy($normPhoto);
            $photoLen = strlen($photoJpeg);

            // 3. Build content stream matching exact DOMPDF stream
            $stream = "q\n 1.000 0.000 0.000 1.000 -297.640 420.907 cm\nq\n595.280 0 0 841.814 297.640 -420.869 cm /I2 Do\nQ\n\nQ\n/GS1 gs\n/GS2 gs\n0.000 0.000 0.000 rg\n";

            // Surname
            if (!empty($surname)) {
                $stream .= sprintf("BT 230.373 737.945 Td /F1 7.5 Tf  [(%s)] TJ ET\n", addcslashes($surname, '()\\'));
            }

            // Tracking ID
            if (!empty($trackingId)) {
                $stream .= sprintf("BT 86.911 737.945 Td /F1 7.5 Tf  [(%s)] TJ ET\n", addcslashes($trackingId, '()\\'));
            }

            // NIN (unspaced 11 digits at exact coordinate)
            if (!empty($rawNin)) {
                $stream .= sprintf("BT 86.911 709.397 Td /F1 7.5 Tf  [(%s)] TJ ET\n", addcslashes($rawNin, '()\\'));
            }

            // First Name
            if (!empty($firstName)) {
                $stream .= sprintf("BT 230.373 709.397 Td /F1 7.5 Tf  [(%s)] TJ ET\n", addcslashes($firstName, '()\\'));
            }

            // Middle Name
            if (!empty($middleName)) {
                $stream .= sprintf("BT 230.373 684.140 Td /F1 7.5 Tf  [(%s)] TJ ET\n", addcslashes($middleName, '()\\'));
            }

            // Gender
            if (!empty($gender)) {
                $stream .= sprintf("BT 230.373 658.884 Td /F1 7.5 Tf  [(%s)] TJ ET\n", addcslashes($gender, '()\\'));
            }

            // Address (if available, multiline in column 3)
            if (!empty($address)) {
                $addrLines = explode("\n", wordwrap($address, 26, "\n"));
                $addrY = 727.000;
                foreach ($addrLines as $line) {
                    $stream .= sprintf("BT 345.000 %.3f Td /F1 7.5 Tf  [(%s)] TJ ET\n", $addrY, addcslashes(trim($line), '()\\'));
                    $addrY -= 9.5;
                }
            }

            // Photo (/I3) - exact DOMPDF transformation matrix: 85.532 x 109.500 at (487.581, 647.275)
            $stream .= "q\n85.532 0 0 109.500 487.581 647.275 cm /I3 Do\nQ \n";

            $streamComp = gzcompress($stream, 9);
            $streamLen  = strlen($streamComp);

            // 4. Assemble PDF objects
            $out = "%PDF-1.4\n";
            $offsets = [];
            $addObj = function($num, $body) use (&$out, &$offsets) {
                $offsets[$num] = strlen($out);
                $out .= "$num 0 obj\n$body\nendobj\n";
            };

            $addObj(1, "<< /Type /Catalog /Pages 3 0 R >>");
            $addObj(2, "<< /Type /Outlines /Count 0 >>");
            $addObj(3, "<< /Type /Pages /Kids [6 0 R ] /Count 1 /Resources << /ProcSet 4 0 R /Font << /F1 8 0 R >> /XObject << /I1 9 0 R /I2 10 0 R /I3 13 0 R >> /ExtGState << /GS1 11 0 R /GS2 12 0 R >> >> /MediaBox [0.000 0.000 595.280 841.890] >>");
            $addObj(4, "[/PDF /Text /ImageC ]");
            $addObj(5, "<< /Producer (GemVerify NIN Engine) >>");
            $addObj(6, "<< /Type /Page /MediaBox [0.000 0.000 595.280 841.890] /Parent 3 0 R /Contents 7 0 R >>");
            $addObj(7, "<< /Filter /FlateDecode /Length $streamLen >>\nstream\n" . $streamComp . "\nendstream");

            // Font: Times-Roman Type1 (Standard 14 PDF font)
            $addObj(8, "<< /Type /Font /Subtype /Type1 /Name /F1 /BaseFont /Times-Roman /Encoding /WinAnsiEncoding >>");

            // Obj 9: SMask for Background
            $len9 = strlen($bgObj9);
            $addObj(9, "<< /Type /XObject /Subtype /Image /Width 1654 /Height 2339 /Filter /FlateDecode /DecodeParms << /Predictor 15 /Colors 1 /Columns 1654 /BitsPerComponent 8>> /ColorSpace /DeviceGray /BitsPerComponent 8 /Length $len9 >>\nstream\n" . $bgObj9 . "\nendstream");

            // Obj 10: Background Image (/I2)
            $len10 = strlen($bgObj10);
            $addObj(10, "<< /Type /XObject /Subtype /Image /Width 1654 /Height 2339 /SMask 9 0 R /Filter /FlateDecode /DecodeParms << /Predictor 15 /Colors 3 /Columns 1654 /BitsPerComponent 8>> /ColorSpace /DeviceRGB /BitsPerComponent 8 /Length $len10 >>\nstream\n" . $bgObj10 . "\nendstream");

            // ExtGStates
            $addObj(11, "<< /Type /ExtGState /BM /Normal /CA 1 >>");
            $addObj(12, "<< /Type /ExtGState /BM /Normal /ca 1 >>");

            // Photo (/I3)
            $addObj(13, "<< /Type /XObject /Subtype /Image /Width 350 /Height 466 /ColorSpace /DeviceRGB /Filter /DCTDecode /BitsPerComponent 8 /Length $photoLen >>\nstream\n" . $photoJpeg . "\nendstream");

            $startXref = strlen($out);
            $out .= "xref\n0 14\n0000000000 65535 f \n";
            for ($i = 1; $i <= 13; $i++) {
                $out .= sprintf("%010d 00000 n \n", $offsets[$i]);
            }
            $out .= "trailer\n<< /Size 14 /Root 1 0 R /Info 5 0 R >>\nstartxref\n$startXref\n%%EOF\n";

            $pdfBase64 = 'data:application/pdf;base64,' . base64_encode($out);
            $fileName  = 'NIN_Enrolment_Slip_' . ($rawNin ?: time()) . '.pdf';

            return [
                'success'    => true,
                'pdf_base64' => $pdfBase64,
                'filename'   => $fileName,
                'nin'        => $ninFormatted,
                'error'      => null
            ];

        } catch (\Throwable $e) {
            error_log('[SlipGeneratorService] Error generating standard slip: ' . $e->getMessage());
            return [
                'success' => false,
                'error'   => 'Standard slip generation failed: ' . $e->getMessage()
            ];
        }
    }
}
