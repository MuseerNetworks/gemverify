<?php
namespace Services;

require_once __DIR__ . '/../Lib/QRCode.php';

/**
 * GemVerify — High-Resolution NIN Slip Generator Service
 *
 * Generates official, high-resolution printable National Identification Number Slips (NINS)
 * from verified citizen demographic data and biometrics.
 * Calibrated strictly to standard CR80 (86mm x 56mm per view) on an A4 document.
 */
class SlipGeneratorService
{
    private const TEMPLATE_PATH = __DIR__ . '/../../../assets/img/templates/nin_highres_slip_template.jpg';
    private const FONT_BOLD     = 'C:/Windows/Fonts/arialbd.ttf';
    private const FONT_REGULAR  = 'C:/Windows/Fonts/arial.ttf';

    /**
     * Generate an official A4 PDF NIN Slip from citizen data.
     *
     * @param array $citizenData Demographic and biometric data from provider
     * @return array ['success' => bool, 'pdf_base64' => string, 'filename' => string, 'error' => ?string]
     */
    public static function generateNinSlip(array $citizenData): array
    {
        try {
            if (!file_exists(self::TEMPLATE_PATH)) {
                return ['success' => false, 'error' => 'Template file not found on server.'];
            }

            // 1. Extract and sanitize fields
            $surname = strtoupper(trim((string)($citizenData['lastName'] ?? $citizenData['surname'] ?? $citizenData['lastname'] ?? '')));
            $firstName = strtoupper(trim((string)($citizenData['firstName'] ?? $citizenData['firstname'] ?? '')));
            $middleName = strtoupper(trim((string)($citizenData['middleName'] ?? $citizenData['middlename'] ?? '')));

            // Name Arrangement: If 3 names, place First + Middle in Given Names
            $givenNames = strtoupper(trim(implode(' ', array_filter([$firstName, $middleName]))));

            // Format Date of Birth: DD MON YYYY (e.g. 28 FEB 2003)
            $rawDob = (string)($citizenData['dateOfBirth'] ?? $citizenData['dob'] ?? '');
            $dobFormatted = !empty($rawDob) ? strtoupper(date('d M Y', strtotime($rawDob))) : '';

            // Format NIN strictly as "XXXX XXX XXXX" (e.g. 9629 433 4378)
            $rawNin = preg_replace('/\D/', '', (string)($citizenData['idNumber'] ?? $citizenData['nin'] ?? ''));
            if (strlen($rawNin) === 11) {
                $ninFormatted = substr($rawNin, 0, 4) . ' ' . substr($rawNin, 4, 3) . ' ' . substr($rawNin, 7, 4);
            } else {
                $ninFormatted = $rawNin;
            }

            // 2. Load base template
            $tpl = @imagecreatefromjpeg(self::TEMPLATE_PATH);
            if (!$tpl) {
                return ['success' => false, 'error' => 'Failed to initialize slip template image.'];
            }

            $white = imagecolorallocate($tpl, 255, 255, 255);
            $black = imagecolorallocate($tpl, 15, 15, 15);

            // 3. Completely delete the placeholder "PHOTO" box and line underneath
            imagefilledrectangle($tpl, 98, 335, 258, 545, $white);

            // 4. Completely delete the placeholder "QR" box without clipping "N G A"
            imagefilledrectangle($tpl, 520, 322, 678, 485, $white);

            // 5. Composite citizen portrait photo
            $photoPlaced = false;
            $rawPhoto = $citizenData['photo'] ?? null;
            if (!empty($rawPhoto) && is_string($rawPhoto)) {
                $photoData = $rawPhoto;
                if (str_contains($photoData, ',')) {
                    $parts = explode(',', $photoData, 2);
                    $photoData = $parts[1];
                }
                $photoBinary = base64_decode($photoData, true);
                if ($photoBinary !== false) {
                    $photoIm = @imagecreatefromstring($photoBinary);
                    if ($photoIm) {
                        // Place photo cleanly in the left space without any box around or under it
                        $photoX = 115;
                        $photoY = 350;
                        $photoW = 124;
                        $photoH = 148;
                        imagecopyresampled($tpl, $photoIm, $photoX, $photoY, 0, 0, $photoW, $photoH, imagesx($photoIm), imagesy($photoIm));
                        imagedestroy($photoIm);
                        $photoPlaced = true;
                    }
                }
            }

            // Fallback placeholder if no photo was supplied
            if (!$photoPlaced) {
                $grey = imagecolorallocate($tpl, 235, 235, 235);
                imagefilledrectangle($tpl, 115, 350, 239, 498, $grey);
            }

            // 6. Generate QR Code including NIN and verified credentials
            $qrLines = ["Surname: {$surname}", "First Name: {$firstName}"];
            if (!empty($middleName)) {
                $qrLines[] = "Middle Name: {$middleName}";
            }
            $qrLines[] = "NIN: {$ninFormatted}";
            if (!empty($dobFormatted)) {
                $qrLines[] = "DOB: {$dobFormatted}";
            }
            $qrText = implode("\n", $qrLines);

            $qr = new \QRCode($qrText, ['w' => 125, 'h' => 125]);
            $qrIm = $qr->render_image();
            if ($qrIm) {
                // Place QR code centered neatly under N G A
                $qrX = 538;
                $qrY = 330;
                imagecopyresampled($tpl, $qrIm, $qrX, $qrY, 0, 0, 125, 125, imagesx($qrIm), imagesy($qrIm));
                imagedestroy($qrIm);
            }

            // 7. Typography - 100% Strict Left-Alignment with labels at X = 263
            $fontBold = file_exists(self::FONT_BOLD) ? self::FONT_BOLD : null;
            $labelX = 263;

            if ($fontBold) {
                // Surname/Nom
                imagettftext($tpl, 12, 0, $labelX, 396, $black, $fontBold, $surname);

                // Given Names/Prenoms (First + Middle combined)
                imagettftext($tpl, 12, 0, $labelX, 451, $black, $fontBold, $givenNames);

                // Date of Birth
                imagettftext($tpl, 12, 0, $labelX, 506, $black, $fontBold, $dobFormatted);

                // NIN - Centered horizontally across card with 15px gap above, 20px gap below
                $fontSize = 20;
                $bbox = imagettfbbox($fontSize, 0, $fontBold, $ninFormatted);
                $textW = $bbox[2] - $bbox[0];
                $ninX = (int)round(387.5 - ($textW / 2));
                $ninY = 603;
                imagettftext($tpl, $fontSize, 0, $ninX, $ninY, $black, $fontBold, $ninFormatted);
            } else {
                imagestring($tpl, 5, $labelX, 386, $surname, $black);
                imagestring($tpl, 5, $labelX, 441, $givenNames, $black);
                imagestring($tpl, 5, $labelX, 496, $dobFormatted, $black);
                imagestring($tpl, 5, 280, 595, $ninFormatted, $black);
            }

            // 8. Convert rendered slip image to High-Quality JPEG buffer
            ob_start();
            imagejpeg($tpl, null, 98);
            $jpegData = ob_get_clean();
            imagedestroy($tpl);

            // 9. Assemble Calibrated A4 PDF (Front: 86mm x 56.5mm, Back: 86mm x 55.5mm)
            $pdfBinary = self::buildCalibratedA4Pdf($jpegData, 774, 1024);
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
     * Wrap JPEG image data into an A4 PDF calibrated so the folded card is 86mm x 56mm.
     */
    private static function buildCalibratedA4Pdf(string $jpegData, int $imgW, int $imgH): string
    {
        $pageW_pt = 595.28;
        $pageH_pt = 841.89;
        $ptPerMm  = 72.0 / 25.4;

        // Target: 86mm wide x 112mm high (56mm front + 56mm back)
        $cardTargetW_pt = 86.0 * $ptPerMm; // 243.78 pt
        $cardTargetH_pt = 112.0 * $ptPerMm; // 317.48 pt

        $cardPixelW = 581.0;
        $cardPixelH = 749.0;

        // Exact canvas draw dimensions on A4:
        $drawW = round($cardTargetW_pt * ($imgW / $cardPixelW), 2); // 324.78 pt
        $drawH = round($cardTargetH_pt * ($imgH / $cardPixelH), 2); // 434.05 pt

        // Center horizontally on A4
        $drawX = round(($pageW_pt - $drawW) / 2, 2);

        // Position on upper-middle of page (120 pt from top)
        $drawY = round($pageH_pt - $drawH - 120, 2);

        $jpegLen = strlen($jpegData);

        $xref = [];
        $pdf = "%PDF-1.4\n";

        $xref[] = strlen($pdf);
        $pdf .= "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n";

        $xref[] = strlen($pdf);
        $pdf .= "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n";

        $xref[] = strlen($pdf);
        $pdf .= "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 $pageW_pt $pageH_pt] /Resources << /XObject << /Im1 4 0 R >> >> /Contents 5 0 R >>\nendobj\n";

        $xref[] = strlen($pdf);
        $pdf .= "4 0 obj\n<< /Type /XObject /Subtype /Image /Width $imgW /Height $imgH /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length $jpegLen >>\nstream\n" . $jpegData . "\nendstream\nendobj\n";

        $stream = sprintf("q\n%.2f 0 0 %.2f %.2f %.2f cm\n/Im1 Do\nQ\n", $drawW, $drawH, $drawX, $drawY);
        $streamLen = strlen($stream);

        $xref[] = strlen($pdf);
        $pdf .= "5 0 obj\n<< /Length $streamLen >>\nstream\n" . $stream . "endstream\nendobj\n";

        $startxref = strlen($pdf);
        $pdf .= "xref\n0 6\n0000000000 65535 f \n";
        foreach ($xref as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        $pdf .= "trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n$startxref\n%%EOF\n";

        return $pdf;
    }
}
